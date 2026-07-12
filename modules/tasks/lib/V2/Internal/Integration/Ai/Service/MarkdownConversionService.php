<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Ai\Service;

use Bitrix\AI\Services\MarkdownToBBCodeTranslationService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

class MarkdownConversionService
{
	public function convertToBbCode(?string $text): ?string
	{
		if ($text === null || $text === '')
		{
			return $text;
		}

		$service = $this->getTranslationService();
		if ($service === null)
		{
			return $text;
		}

		return $service->convert($text);
	}

	protected function getTranslationService(): ?MarkdownToBBCodeTranslationService
	{
		if (!Loader::includeModule('ai') || !class_exists(MarkdownToBBCodeTranslationService::class))
		{
			return null;
		}

		return ServiceLocator::getInstance()->get(MarkdownToBBCodeTranslationService::class);
	}
}
