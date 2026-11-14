<?php

declare(strict_types=1);

namespace Bitrix\AI\Integration\Fileman;

use Bitrix\AiAssistant\Config\Feature;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;

final class HtmlEditorHandler
{
	private const DESIGN_TOKENS_EXTENSION = 'ui.design-tokens.air';

	private const IFRAME_CSS = <<<'CSS'
[bxhtmled-copilot-result-node="true"],
[bxhtmled-copilot-result-node="true"] > * {
	color: var(--ui-color-accent-main-primary, #0075ff) !important;
}

@supports ((background-clip: text) or (-webkit-background-clip: text)) {
	[bxhtmled-copilot-result-node="true"],
	[bxhtmled-copilot-result-node="true"] > * {
		background: linear-gradient(90deg,
			var(--ui-color-design-outline-bitrix-gpt-content-gradient-1, #0098ea) 0%,
			var(--ui-color-design-outline-bitrix-gpt-content-gradient-2, #3f68ff) 25%,
			var(--ui-color-design-outline-bitrix-gpt-content-gradient-3, #9d48ff) 50%,
			var(--ui-color-design-outline-bitrix-gpt-content-gradient-4, #f046b7) 75%,
			var(--ui-color-design-outline-bitrix-gpt-content-gradient-5, #f96269) 100%
		);
		-webkit-background-clip: text;
		background-clip: text;
		color: transparent !important;
		-webkit-text-fill-color: transparent;
	}
}
CSS;

	public static function onBeforeBuild(Event $event): void
	{
		$htmlEditor = $event->getParameter(0);
		if (!$htmlEditor instanceof \CHTMLEditor)
		{
			return;
		}

		$config = $htmlEditor->getConfig();
		if (($config['isCopilotEnabled'] ?? null) !== true)
		{
			return;
		}

		if (!Loader::includeModule('aiassistant') || !Feature::getInstance()->isBitrixGptV2Available())
		{
			return;
		}

		$designTokens = is_string($config['designTokens'] ?? null) ? $config['designTokens'] : '';
		$iframeCss = is_string($config['iframeCss'] ?? null) ? $config['iframeCss'] : '';
		foreach (Extension::getAssets(self::DESIGN_TOKENS_EXTENSION)['css'] as $cssPath)
		{
			$cssUrl = htmlspecialcharsbx(\CUtil::GetAdditionalFileURL($cssPath));
			$designTokens .= '<link rel="stylesheet" href="' . $cssUrl . '">';
		}

		$htmlEditor->setOption('designTokens', $designTokens);
		$htmlEditor->setOption('iframeCss', $iframeCss . "\n" . self::IFRAME_CSS);
	}
}
