<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\AI\SiteBuilder\Prompt\PromptCodeCatalog;

class RequestChangeAiSiteSelectedElementBlockHtml extends RequestChangeAiSiteBlockHtml
{
	private const MARKER_SELECTED_SELECTOR = '{{selected_selector}}';
	private const MARKER_SELECTED_ELEMENT_HTML = '{{selected_element_html}}';

	protected function resolveBlockPromptCode(): string
	{
		return PromptCodeCatalog::CHANGE_AI_SITE_SELECTED_ELEMENT_HTML;
	}

	protected function appendUpdateBlockSpecificMarkers(array $markers, array $item): array
	{
		$markers[self::MARKER_SELECTED_SELECTOR] = trim((string)($item['selectedSelector'] ?? ''));
		// transitional empty value: unsent keys stay in the prompt text as literals,
		// so the key keeps wiping the marker out of not-yet-updated prompt base texts
		$markers[self::MARKER_SELECTED_ELEMENT_HTML] = '';

		return $markers;
	}
}
