<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Domain;
use Bitrix\Landing\Landing;
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

	/**
	 * The first page of the site (folders are not pages), as search_landing_sites gives it; 0 without pages.
	 */
	protected function resolveFirstPageId(int $siteId): int
	{
		$row = Landing::getList([
			'select' => ['ID'],
			'filter' => [
				'SITE_ID' => $siteId,
				'=FOLDER' => 'N',
			],
			'order' => ['ID' => 'ASC'],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? (int)$row['ID'] : 0;
	}
}
