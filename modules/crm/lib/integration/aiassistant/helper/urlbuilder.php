<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Helper;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Router;
use Bitrix\Main\Engine\Response\DataType\ContentUri;
use Bitrix\Main\Engine\UrlManager;

final class UrlBuilder
{
	public function buildDetailUrl(int $entityTypeId, int $itemId, ?int $categoryId = null): ?ContentUri
	{
		$detailLink = $this
			->getRouter()
			->getItemDetailUrl($entityTypeId, $itemId, $categoryId)
		;
		if (!$detailLink)
		{
			return null;
		}

		return new ContentUri(UrlManager::getInstance()->getHostUrl() . $detailLink->getLocator());
	}

	public function buildListUrl(
		string $keyword,
		array $filterParams,
		int $entityTypeId,
		?int $categoryId = null
	): ?ContentUri
	{
		$itemListLink = $this
			->getRouter()
			->getItemListUrlInCurrentView($entityTypeId, $categoryId)
		;
		if (!$itemListLink)
		{
			return null;
		}

		if (empty($keyword) && empty($filterParams))
		{
			return new ContentUri(UrlManager::getInstance()->getHostUrl() . $itemListLink->getLocator());
		}

		$itemListLink->addParams(['apply_filter' => 'Y']);

		if (!empty($keyword))
		{
			$itemListLink->addParams(['FIND' => $keyword]);
		}

		if (!empty($filterParams))
		{
			$itemListLink->addParams($filterParams);
		}

		return new ContentUri(UrlManager::getInstance()->getHostUrl() . $itemListLink->getLocator());
	}

	private function getRouter(): Router
	{
		return Container::getInstance()->getRouter();
	}
}
