<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;

final class AiSiteChatContextService
{
	private const SUGGESTED_TITLE_MAX_LENGTH = 255;
	private const CONTEXT_TITLE_MAX_LENGTH = 255;
	private const INITIAL_MESSAGE_MAX_LENGTH = 4000;

	public function getSitesAiContext(int $bindingId, string $suggestedTitle = '', string $initialMessage = ''): array
	{
		return $this->buildContext(
			AiSiteChatBindingContract::CONTEXT_SURFACE_SITES_AI,
			$bindingId,
			[],
			$suggestedTitle,
			$initialMessage,
		);
	}

	public function getEditorContext(
		int $bindingId,
		int $siteId,
		int $pageId,
		string $siteTitle = '',
		string $pageTitle = '',
		string $suggestedTitle = '',
		string $initialMessage = '',
	): array
	{
		$contextData = [
			'siteId' => $siteId,
			'pageId' => $pageId,
		];

		$siteTitle = $this->sanitizeText($siteTitle, self::CONTEXT_TITLE_MAX_LENGTH);
		if ($siteTitle !== '')
		{
			$contextData['siteTitle'] = $siteTitle;
		}

		$pageTitle = $this->sanitizeText($pageTitle, self::CONTEXT_TITLE_MAX_LENGTH);
		if ($pageTitle !== '')
		{
			$contextData['pageTitle'] = $pageTitle;
		}

		return $this->buildContext(
			AiSiteChatBindingContract::CONTEXT_SURFACE_EDITOR,
			$bindingId,
			$contextData,
			$suggestedTitle,
			$initialMessage,
		);
	}

	private function buildContext(
		string $surface,
		int $bindingId,
		array $contextData,
		string $suggestedTitle,
		string $initialMessage,
	): array
	{
		$context = [
			'contextData' => array_merge([
				'module' => AiSiteChatBindingContract::CONTEXT_MODULE,
				'surface' => $surface,
				'bindingId' => $bindingId,
			], $contextData),
		];

		$suggestedTitle = $this->sanitizeText($suggestedTitle, self::SUGGESTED_TITLE_MAX_LENGTH);
		if ($suggestedTitle !== '')
		{
			$context['suggestedTitle'] = $suggestedTitle;
		}

		$initialMessage = $this->sanitizeText($initialMessage, self::INITIAL_MESSAGE_MAX_LENGTH);
		if ($initialMessage !== '')
		{
			$context['initialMessage'] = $initialMessage;
		}

		return $context;
	}

	private function sanitizeText(string $text, int $maxLength): string
	{
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
		if (!is_string($text))
		{
			return '';
		}

		$text = trim($text);
		if (mb_strlen($text) > $maxLength)
		{
			$text = mb_substr($text, 0, $maxLength);
		}

		return $text;
	}
}
