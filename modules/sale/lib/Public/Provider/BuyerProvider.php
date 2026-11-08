<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Provider;

use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Sale\Internal\Repository\BuyerRepository;
use Bitrix\Sale\Public\Dto\BuyerStatisticCollection;
use Bitrix\Sale\Public\Provider\Params\BuyerFilter;
use Bitrix\Sale\Public\Provider\Params\BuyerSort;

/**
 * Rows of the buyers list: the statistic of a buyer on one site in one currency, with the buyer.
 *
 * A buyer who has paid in two currencies or on two sites is two rows of the list, each with its own
 * sum, so the list is sorted and filtered by the same numbers it shows, in either mode of the page.
 * Made of the group of the shop buyers, the list also has a row of zeros for every member who has
 * paid for nothing yet.
 */
final class BuyerProvider
{
	public function __construct(private readonly BuyerRepository $repository = new BuyerRepository())
	{
	}

	/**
	 * @param PagerInterface|null $pager page of the list, null for the whole of it
	 * @param int $excludedUserId buyer the list never shows, such as the anonymous one; 0 for none
	 * @param int $shopGroupId group of the shop buyers the list is made of; 0 for the statistic alone
	 */
	public function getBuyers(
		BuyerFilter $filter,
		BuyerSort $sort,
		?PagerInterface $pager = null,
		int $excludedUserId = 0,
		int $shopGroupId = 0,
	): BuyerStatisticCollection
	{
		return $this->repository->getList($filter, $sort, $pager, $excludedUserId, $shopGroupId);
	}

	/**
	 * Number of rows getBuyers() returns for the same conditions: the size of the list for its paging.
	 */
	public function countBuyers(BuyerFilter $filter, int $excludedUserId = 0, int $shopGroupId = 0): int
	{
		return $this->repository->getCount($filter, $excludedUserId, $shopGroupId);
	}
}
