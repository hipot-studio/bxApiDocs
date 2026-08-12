<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Domain;
use Bitrix\Main\Result;

abstract class AbstractSiteHandler
{
	protected function getFirstErrorMessage(Result $result, string $fallback): string
	{
		$messages = $result->getErrorMessages();

		return !empty($messages) ? (string)reset($messages) : $fallback;
	}

	protected function normalizeCode(?string $code): ?string
	{
		if ($code === null)
		{
			return null;
		}

		$normalized = trim($code, '/');

		return $normalized !== '' ? $normalized : null;
	}

	protected function getSitePagesListUrl(int $siteId): string
	{
		return rtrim(Domain::getHostUrl(), '/') . '/sites/site/' . $siteId . '/';
	}

	protected function getSitePageEditorUrl(int $siteId, int $pageId): string
	{
		return rtrim(Domain::getHostUrl(), '/') . '/sites/site/' . $siteId . '/view/' . $pageId . '/';
	}
}
