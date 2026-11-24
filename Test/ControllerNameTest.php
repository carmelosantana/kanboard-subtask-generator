<?php

namespace Kanboard\Plugin\SubtaskGenerator\Test;

use PHPUnit\Framework\TestCase;

/**
 * Kanboard's AccessMap keys rules by the short controller name alone, so a plugin's settings
 * controller carries the plugin's name: another plugin's rule for a shared `SettingsController`
 * would govern this one too (#7337).
 */
class ControllerNameTest extends TestCase
{
    public function testSettingsControllerHasAPluginScopedName(): void
    {
        $this->assertTrue(class_exists('Kanboard\Plugin\SubtaskGenerator\Controller\SubtaskGeneratorSettingsController'));
        $this->assertFalse(class_exists('Kanboard\Plugin\SubtaskGenerator\Controller\SettingsController'));
    }

    /** AI Connector renamed its controller the same way (1.2.1), so the link to its settings follows (plugin.json requires 1.2.1). */
    public function testAiConnectorLinkNamesItsScopedControllerAndVersion(): void
    {
        $tpl = file_get_contents(dirname(__DIR__).'/Template/config/settings.php');
        $this->assertStringContainsString("'AiConnectorSettingsController', 'show', ['plugin' => 'AiConnector']", $tpl);
    }
}
