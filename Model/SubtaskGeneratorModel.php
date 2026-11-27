<?php

namespace Kanboard\Plugin\SubtaskGenerator\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\AiConnector\Model\AiQuota;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

/**
 * SubtaskGeneratorModel
 *
 * Asks AiConnector's ProviderRegistry for a structured() result against a
 * {subtasks:[{title}]} schema, then normalises/dedupes/clamps to string titles.
 * All provider selection, key handling, and php-agents coupling live in
 * AiConnector — this model references no vendored provider SDK class directly.
 *
 * @package Kanboard\Plugin\SubtaskGenerator\Model
 * @author  Carmelo Santana
 */
class SubtaskGeneratorModel extends Base
{
    public const DEFAULT_MAX_SUBTASKS = 8;

    /** JSON schema passed to structured(). Name matches the tool name. */
    public const SCHEMA = [
        'name'   => 'subtasks',
        'schema' => [
            'type'       => 'object',
            'properties' => [
                'subtasks' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => ['title' => ['type' => 'string']],
                        'required'   => ['title'],
                    ],
                ],
            ],
            'required' => ['subtasks'],
        ],
    ];

    private const SYSTEM_PROMPT = 'Break the task into concrete, actionable subtasks.';

    /** Optional registry override — injected in tests; null → built from container. */
    /** The AiConnector routing feature id (also the AiQuota feature). */
    public const FEATURE = 'subtaskgenerator.generate';

    private ?ProviderRegistry $injectedRegistry = null;

    /** Inject a registry (with a fake provider) so unit tests make no network call. */
    public function setRegistry(ProviderRegistry $registry): void
    {
        $this->injectedRegistry = $registry;
    }

    /** Optional AiQuota override (has consume()) — injected in tests; null → built from container. */
    private ?object $injectedQuota = null;

    public function setQuota(object $quota): void
    {
        $this->injectedQuota = $quota;
    }

    /**
     * Generate candidate subtask titles for the given prompt.
     *
     * @param  string      $prompt    Free-text prompt (task title + description).
     * @param  string|null $profileId AiConnector profile id (null → default).
     * @return string[]               Normalised, deduped, clamped titles.
     * @throws SubtaskGeneratorRefused when AiConnector's quota or input cap refuses the call.
     * @throws \RuntimeException on provider error (caller must catch).
     */
    public function generate(string $prompt, ?string $profileId = null): array
    {
        $registry = $this->injectedRegistry ?? new ProviderRegistry($this->container);

        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user',   'content' => $prompt],
        ];

        $quota = $this->injectedQuota ?? new AiQuota($this->container);
        $refusal = $quota->consume((int) $this->userSession->getId(), self::FEATURE, [...array_column($messages, 'content'), json_encode(self::SCHEMA)]);
        if ($refusal !== null) {
            throw new SubtaskGeneratorRefused($refusal);
        }

        $decoded = $registry->structured($messages, json_encode(self::SCHEMA), $profileId);

        return $this->normalise($decoded);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Validate + normalise the decoded array into clean string titles:
     * extract subtasks → trim → drop blanks/non-strings → case-insensitive dedupe
     * → clamp to sg_max_subtasks.
     *
     * @param  array<string, mixed> $data
     * @return string[]
     */
    private function normalise(array $data): array
    {
        $rawItems = $data['subtasks'] ?? [];
        if (! is_array($rawItems)) {
            return [];
        }

        $max = (int) $this->configModel->get('sg_max_subtasks', (string) self::DEFAULT_MAX_SUBTASKS);
        if ($max < 1) {
            $max = self::DEFAULT_MAX_SUBTASKS;
        }

        $titles = [];
        $seen   = [];

        foreach ($rawItems as $item) {
            if (! is_array($item)) {
                if (is_string($item)) {
                    $title = trim($item);
                    if ($title === '') { continue; }
                    $lower = mb_strtolower($title);
                    if (isset($seen[$lower])) { continue; }
                    $seen[$lower] = true;
                    $titles[] = $title;
                }
                continue;
            }

            $raw = $item['title'] ?? null;
            if (! is_string($raw)) { continue; }

            $title = trim($raw);
            if ($title === '') { continue; }

            $lower = mb_strtolower($title);
            if (isset($seen[$lower])) { continue; }

            $seen[$lower] = true;
            $titles[] = $title;
        }

        return array_slice($titles, 0, $max);
    }
}
