<?php

require_once 'tests/units/Base.php';

use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Kanboard\Plugin\SubtaskGenerator\Controller\GeneratorController;
use Kanboard\Plugin\SubtaskGenerator\Model\AiGate;
use Kanboard\Plugin\SubtaskGenerator\Model\SubtaskGeneratorModel;
use Kanboard\Plugin\SubtaskGenerator\Plugin;
use KanboardTests\units\Base;

/**
 * AiConnector model routing (1.4.0): feature registration, the 1.4.0 floor,
 * resolve() with the task's project and the posted ai_profile, and the shared picker.
 */
class RoutingTest extends Base
{
    private function stubSession(): void
    {
        $this->container['userSession'] = $this->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['isAdmin', 'getId', 'isLogged'])
            ->getMock();
        $this->container['userSession']->method('isAdmin')->willReturn(true);
        $this->container['userSession']->method('isLogged')->willReturn(true);
        $this->container['userSession']->method('getId')->willReturn(1);
    }

    private function stubRequest(int $taskId, string $pick): void
    {
        $mock = $this->getMockBuilder(\Kanboard\Core\Http\Request::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['getIntegerParam', 'getStringParam', 'getRawValue'])
            ->getMock();
        $mock->method('getIntegerParam')->willReturnCallback(fn (string $p) => $p === 'task_id' ? $taskId : 0);
        $mock->method('getStringParam')->willReturn('');
        $mock->method('getRawValue')->willReturnCallback(fn (string $n) => $n === 'ai_profile' ? $pick : null);
        $this->container['request'] = $mock;
        $this->container['response'] = $this->getMockBuilder(\Kanboard\Core\Http\Response::class)
            ->disableOriginalConstructor()->onlyMethods(['json', 'html', 'redirect'])->getMock();
    }

    /** @return array{0:int,1:int} project id, task id */
    private function seedTask(): array
    {
        $projectId = (new ProjectModel($this->container))->create(['name' => 'Routed']);
        $taskId = (new TaskCreationModel($this->container))->create(['title' => 'T', 'project_id' => $projectId]);
        return [$projectId, $taskId];
    }

    /** A registry that records resolve()/pickerOptions() calls and returns $resolved. */
    private function stubRegistry(string $resolved): ProviderRegistry
    {
        return new class($this->container, $resolved) extends ProviderRegistry {
            public array $resolveCalls = [];
            public array $pickerCalls = [];
            public function __construct($c, private string $resolved) { parent::__construct($c); }
            public function resolve(string $feature, ?int $projectId = null, ?string $pick = null): string
            {
                $this->resolveCalls[] = [$feature, $projectId, $pick];
                return $this->resolved;
            }
            public function pickerOptions(string $feature, ?int $projectId = null): array
            {
                $this->pickerCalls[] = [$feature, $projectId];
                return ['show' => false, 'profiles' => [], 'selected' => ''];
            }
        };
    }

    /** A controller with the gate open and a stub registry + recording model. */
    private function controller(ProviderRegistry $registry, ?object &$model = null): GeneratorController
    {
        $model = new class($this->container) extends SubtaskGeneratorModel {
            public array $generateCalls = [];
            public function generate(string $prompt, ?string $profileId = null): array
            {
                $this->generateCalls[] = $profileId;
                return ['A'];
            }
        };
        return new class($this->container, $registry, $model) extends GeneratorController {
            public function __construct($c, private ProviderRegistry $reg, private SubtaskGeneratorModel $mdl) { parent::__construct($c); }
            protected function isAiEnabled(): bool { return true; }
            protected function checkCSRFForm() {}
            protected function getRegistry(): ProviderRegistry { return $this->reg; }
            protected function getGeneratorModel(): SubtaskGeneratorModel { return $this->mdl; }
        };
    }

    public function testTheGenerateFeatureIsRegistered(): void
    {
        $plugin = new Plugin($this->container);
        $plugin->initialize();
        $entries = $this->container['hook']->getListeners(ProviderRegistry::FEATURES_HOOK);
        $this->assertNotEmpty($entries, 'initialize() must listen on aiconnector:features');
        $features = [];
        foreach ($entries as $listener) {
            foreach ($listener() as $f) {
                $features[$f['id']] = $f;
            }
        }
        $this->assertSame('SubtaskGenerator', $features['subtaskgenerator.generate']['plugin']);
        $this->assertNotSame('', $features['subtaskgenerator.generate']['label']);
    }

    public function testPluginJsonRequiresAiConnector140(): void
    {
        $json = json_decode(file_get_contents(dirname(__DIR__) . '/plugin.json'), true);
        $this->assertSame('1.4.0', $json['requires'][0]['min_version']);
        $this->assertArrayNotHasKey('recommends', $json);
    }

    public function testTheGateChecksForResolve(): void
    {
        $this->assertStringContainsString("method_exists(", file_get_contents(dirname(__DIR__) . '/Model/AiGate.php'));
        $this->assertStringContainsString("'resolve'", file_get_contents(dirname(__DIR__) . '/Model/AiGate.php'));
    }

    public function testGenerateResolvesWithTheTasksProjectAndThePostedPick(): void
    {
        $this->stubSession();
        [$projectId, $taskId] = $this->seedTask();
        $this->stubRequest($taskId, 'forged-or-not');
        $registry = $this->stubRegistry('p-resolved');
        $ctrl = $this->controller($registry, $model);

        $ctrl->generate();

        $this->assertSame([['subtaskgenerator.generate', $projectId, 'forged-or-not']], $registry->resolveCalls);
        $this->assertSame(['p-resolved'], $model->generateCalls, 'the provider gets resolve()\'s answer, never the posted value');
    }

    public function testAForgedPickIsNeverPassedToTheModel(): void
    {
        $this->stubSession();
        [, $taskId] = $this->seedTask();
        $this->stubRequest($taskId, 'not-a-profile');
        $registry = $this->stubRegistry('the-default');
        $ctrl = $this->controller($registry, $model);

        $ctrl->generate();

        $this->assertNotContains('not-a-profile', $model->generateCalls);
        $this->assertSame(['the-default'], $model->generateCalls);
    }

    public function testNothingReadyMakesNoCall(): void
    {
        $this->stubSession();
        [, $taskId] = $this->seedTask();
        $this->stubRequest($taskId, 'x');
        $registry = $this->stubRegistry('');
        $ctrl = $this->controller($registry, $model);

        $this->container['response']->expects($this->once())->method('json')
            ->with($this->callback(fn ($b) => isset($b['error']) && ! isset($b['subtasks'])));
        $ctrl->generate();

        $this->assertSame([], $model->generateCalls, 'no provider call when resolve() returns an empty id');
    }

    public function testShowComputesPickerOptionsForTheTasksProject(): void
    {
        $this->stubSession();
        [$projectId, $taskId] = $this->seedTask();
        $this->stubRequest($taskId, '');
        $registry = $this->stubRegistry('x');
        $ctrl = $this->controller($registry);
        $this->container['helper']->user = new class { public function hasProjectAccess() { return true; } };

        try {
            $ctrl->show();
        } catch (\Throwable $e) {
            // rendering the modal may need a full request; the picker call is what matters
        }

        $this->assertSame([['subtaskgenerator.generate', $projectId]], $registry->pickerCalls);
    }

    public function testTheModalUsesTheSharedPickerNotItsOwnSelect(): void
    {
        $tpl = file_get_contents(dirname(__DIR__) . '/Template/generator/modal.php');
        $this->assertStringContainsString("AiConnector:picker/select", $tpl);
        $this->assertStringNotContainsString('sg_profile', $tpl);
        $this->assertStringNotContainsString('default_profile_id', $tpl);
    }

    public function testTheModalRendersThePickerFieldAiProfile(): void
    {
        $task = ['id' => 1, 'project_id' => 1, 'title' => 'T', 'description' => ''];
        $html = $this->container['template']->render('SubtaskGenerator:generator/modal', [
            'task' => $task,
            'sg_prompt' => 'T',
            'ai_options' => ['show' => true, 'selected' => 'b', 'profiles' => [
                ['id' => 'a', 'label' => 'A', 'provider' => 'anthropic', 'model' => 'm'],
                ['id' => 'b', 'label' => 'B', 'provider' => 'openai', 'model' => 'm'],
            ]],
        ]);
        $this->assertStringContainsString('name="ai_profile"', $html);
        $this->assertStringNotContainsString('name="sg_profile"', $html);

        $hidden = $this->container['template']->render('SubtaskGenerator:generator/modal', [
            'task' => $task, 'sg_prompt' => 'T', 'ai_options' => ['show' => false, 'profiles' => [], 'selected' => ''],
        ]);
        $this->assertStringNotContainsString('ai_profile', $hidden);
    }

    public function testTheControllerNoLongerChecksKnownIds(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Controller/GeneratorController.php');
        $this->assertStringNotContainsString('sg_profile', $src);
        $this->assertStringNotContainsString("array_column(\$registry->listProfiles()", $src);
        $this->assertStringContainsString('->resolve(', $src);
    }
}
