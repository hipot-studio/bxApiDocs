<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\Render;

/**
 * Renders a component which produces a whole HTML document by itself.
 * Such a component must not be wrapped into the portal slider markup: the browser drops the nested
 * html/body tags and the page loses the body class its layout relies on.
 */
final class StandalonePageRenderer
{
	public static function render(string $componentName, string $templateName = '', array $params = []): string
	{
		$application = $GLOBALS['APPLICATION'];
		$application->RestartBuffer();
		$application->includeComponent($componentName, $templateName, $params);

		foreach (GetModuleEvents('main', 'OnEpilog', true) as $event)
		{
			ExecuteModuleEventEx($event);
		}

		return $application->EndBufferContentMan();
	}
}
