<?php

namespace Bitrix\Crm\Search\Result\Provider;

use Bitrix\Crm\Search\Result;
use Bitrix\Crm\Search\Result\Provider;
use Bitrix\Crm\Search\SearchEnvironment;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory\Order as OrderFactory;
use Bitrix\Main\ORM\Query\Filter\Helper;

/**
 * The order factory does not work with items, so orders are read from its data class directly
 */
class OrderProvider extends Provider
{
	public function __construct(private readonly OrderFactory $factory)
	{
		parent::__construct();
	}

	public function getSearchResult(string $searchQuery): Result
	{
		$result = new Result();

		$searchQuery = trim($searchQuery);
		if ($searchQuery === '')
		{
			return $result;
		}

		$userPermissions = Container::getInstance()->getUserPermissions($this->userId);
		if ($userPermissions->getUserId() === 0)
		{
			return $result;
		}

		$filter = [];
		$words = Helper::splitWords($searchQuery);
		$minTokenSize = Helper::getMinTokenSize();
		$fullTextWords = array_filter($words, static fn(string $word) => mb_strlen($word) >= $minTokenSize);
		if (empty($fullTextWords))
		{
			// the full-text search skips short words, so a query of short words (usually an order number,
			// e.g. "12" or "#12") is matched with order numbers exactly
			$filter['@ACCOUNT_NUMBER'] = array_values(array_unique([$searchQuery, ...$words]));
		}
		else
		{
			$filter['SEARCH_CONTENT'] = $searchQuery;
			SearchEnvironment::prepareSearchFilter(
				\CCrmOwnerType::Order,
				$filter,
				[
					'ENABLE_PHONE_DETECTION' => false,
				],
			);
		}

		$parameters = $userPermissions->itemsList()->applyAvailableItemsGetListParameters(
			[
				'select' => ['ID'],
				'filter' => array_merge($filter, $this->additionalFilter),
				'order' => ['ID' => 'DESC'],
				'limit' => $this->limit,
			],
			[\CCrmOwnerType::OrderName],
		);

		$orders = $this->factory->getDataClass()::getList($parameters);
		while ($order = $orders->fetch())
		{
			$result->addId((int)$order['ID']);
		}

		return $result;
	}
}
