<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Search\Helper;

use Bitrix\Crm\Integration\AiAssistant\Helper\UrlBuilder;
use Bitrix\Crm\Search\Result;
use Bitrix\Crm\Search\Result\Factory;
use Bitrix\Main\Engine\Response\DataType\ContentUri;
use Bitrix\Main\Web\Json;
use CCrmCurrency;
use CCrmOwnerType;
use Throwable;

final class ResponseFormatter
{
	public function formatItemsListResponse(array $items, ?ContentUri $itemsUrl = null): string
	{
		$result = [
			'items' => $items,
		];

		if ($itemsUrl !== null)
		{
			$result['items_url'] = $itemsUrl->getUri();
		}

		return Json::encode($result);
	}

	public function formatItemsResponse(Result $searchResult, int $entityTypeId, ?int $categoryId): array
	{
		try
		{
			$adapter = Factory::createResultAdapter($entityTypeId, $categoryId);
		}
		catch (Throwable)
		{
			return [];
		}

		$items = $adapter->adapt($searchResult);
		if (empty($items))
		{
			return [];
		}

		$urlBuilder = new UrlBuilder();

		$results = [];
		foreach ($items as $item)
		{
			$row = [
				'id' => $item->getId(),
				'title' => htmlspecialcharsbx($item->getTitle()) ?: $this->getEntityName($entityTypeId) . ' #' . $item->getId(),
			];

			$detailUrl = $urlBuilder->buildDetailUrl($entityTypeId, (int)$item->getId(), $categoryId);
			if ($detailUrl !== null)
			{
				$row['detail_url'] = $detailUrl->getUri();
			}

			$results[] = $row;
		}

		return $results;
	}

	public function formatAmountResponse(float $amount): string
	{
		return Json::encode([
			'amount_number' => $amount,
			'amount_with_currency' => CCrmCurrency::MoneyToString(
				$amount,
				CCrmCurrency::GetAccountCurrencyID()
			),
		]);
	}

	public function formatStagesResponse(array $stages): string
	{
		$result = [];
		foreach ($stages as $stage)
		{
			$result[] = [
				'id' => $stage->getStatusId(),
				'name' => htmlspecialcharsbx($stage->getName()),
			];
		}

		return Json::encode($result);
	}

	public function formatCategoriesResponse(array $categories): string
	{
		$result = [];
		foreach ($categories as $category)
		{
			$result[] = [
				'id' => $category->getId(),
				'name' => htmlspecialcharsbx($category->getName()),
			];
		}

		return Json::encode($result);
	}

	private function getEntityName(int $entityTypeId): string
	{
		return CCrmOwnerType::GetDescription($entityTypeId);
	}
}
