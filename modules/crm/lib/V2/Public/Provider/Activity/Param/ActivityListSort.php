<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Activity\Param;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Order;

/**
 * The order of {@see \Bitrix\Crm\V2\Public\Provider\Activity\ActivityProvider::getList()}: fields of the model of
 * an activity with their directions, in the order of precedence. The provider always ends it with the
 * identifier, so that a page neither repeats nor skips an activity of its neighbour.
 */
final class ActivityListSort
{
	/**
	 * @param array<string, Order> $order
	 * @throws ArgumentException a direction that is not an {@see Order}.
	 */
	public function __construct(
		private readonly array $order = [],
	)
	{
		foreach ($order as $direction)
		{
			if (!$direction instanceof Order)
			{
				throw new ArgumentException('A direction of the order should be an Order.', 'order');
			}
		}
	}

	/**
	 * @return array<string, Order>
	 */
	public function getOrder(): array
	{
		return $this->order;
	}
}
