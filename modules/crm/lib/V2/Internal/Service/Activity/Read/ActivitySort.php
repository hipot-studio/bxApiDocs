<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Read;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Order;

/**
 * The order of a list of activities over the fields of the model.
 *
 * It always ends with the identifier: equal values of the other fields would otherwise leave the order of
 * their activities to the storage, and a page could repeat or skip an activity of its neighbour.
 */
final class ActivitySort
{
	public const FIELDS = [
		Activity::id,
		Activity::createdTime,
		Activity::updatedTime,
		Activity::startTime,
		Activity::endTime,
		Activity::deadline,
	];

	/** @var array<string, Order> */
	private readonly array $order;

	/**
	 * @param array<string, Order> $order field of the model => direction, in the order of precedence.
	 * @throws ArgumentException a field outside {@see self::FIELDS} or a direction that is not an {@see Order}.
	 */
	public function __construct(array $order = [])
	{
		foreach ($order as $field => $direction)
		{
			if (!in_array($field, self::FIELDS, true) || !$direction instanceof Order)
			{
				throw new ArgumentException('The activity list cannot be sorted this way.', 'order');
			}
		}

		$order[Activity::id] ??= Order::Asc;
		$this->order = $order;
	}

	/**
	 * @return array<string, Order>
	 */
	public function getOrder(): array
	{
		return $this->order;
	}
}
