<?php

namespace Bitrix\Crm\RepeatSale\DataCollector\Activity;

/**
 * A strategy that tells which row every record of it came from.
 *
 * The records of such a strategy are picked by the bindings of a row and not by its owner, so a caller
 * that reads several entities of one client meets the same row once per entity it is bound to. Telling
 * those repeats apart by the text would also drop two rows that happen to say the same thing, and the
 * identifier is what separates the two cases.
 */
interface IdentifiedRecordsInterface
{
	/**
	 * @return array<int, array{id: int, text: string}> The records {@see StrategyInterface::collect()}
	 * answers, in the same order, each with the row it was made of.
	 */
	public function collectIdentified(int $entityId, int $limit): array;
}
