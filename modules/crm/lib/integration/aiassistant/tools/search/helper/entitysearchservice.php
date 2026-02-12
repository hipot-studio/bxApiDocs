<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Search\Helper;

use Bitrix\Crm\Item;
use Bitrix\Crm\Search\Result;
use Bitrix\Crm\Search\Result\Factory;
use Bitrix\Crm\Service\Container;
use Throwable;

final class EntitySearchService
{
	public function __construct(
		private readonly int $entityTypeId,
		private readonly int $userId,
		private readonly int $limit,
		private readonly array $filter,
	)
	{}

	public function searchByKeyword(string $keyword): Result
	{
		try
		{
			$provider = Factory::createProvider($this->entityTypeId);
		}
		catch (Throwable)
		{
			return new Result();
		}

		$provider->setUserId($this->userId);
		$provider->setLimit($this->limit);

		if (!empty($this->filter))
		{
			$provider->setAdditionalFilter($this->filter);
		}

		return $provider->getSearchResult($keyword);
	}

	public function searchByFilter(): Result
	{
		$searchResult = new Result();
		$factory = Container::getInstance()->getFactory($this->entityTypeId);
		if ($factory === null)
		{
			return $searchResult;
		}

		$items = $factory
			->getItemsFilteredByPermissions([
				'select' => [Item::FIELD_NAME_ID],
				'filter' => $this->filter,
				'limit' => $this->limit,
			], $this->userId)
		;
		foreach ($items as $item)
		{
			$searchResult->addId($item->getId());
		}

		return $searchResult;
	}
}
