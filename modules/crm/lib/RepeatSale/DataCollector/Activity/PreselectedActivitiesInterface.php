<?php

namespace Bitrix\Crm\RepeatSale\DataCollector\Activity;

/**
 * A strategy that can be told which activities to collect instead of looking for them itself.
 *
 * Both ways answer the same records of the same kind, and a caller that already knows the activities
 * saves the query the strategy would have sent to find them. Only the strategies whose records are
 * picked by the owner of the activity can be told this way: the owner is what such a caller reads by.
 */
interface PreselectedActivitiesInterface
{
	/**
	 * @param int[] $activityIds The activities of one entity, in the order the records are wanted in,
	 * already cut down to the count the caller allows and already of the kind of the strategy.
	 * @return array The records {@see StrategyInterface::collect()} would have answered of them.
	 */
	public function collectByActivityIds(array $activityIds): array;
}
