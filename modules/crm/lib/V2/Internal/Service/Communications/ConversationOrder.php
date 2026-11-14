<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Communications;

use Bitrix\Main\Type\DateTime;

/**
 * Which of two conversations is the later one, decided in one place for everyone who orders them: the
 * read of a single source and the merge of all of them answer the same list, and a key they disagreed
 * on would make the merge shuffle what the read had already chosen.
 *
 * The order carries a second key on purpose: a ceiling cuts a list ordered by the moment alone, and
 * conversations of the same second would come back a different, equally fresh subset from one read to
 * the next. The key is the primary one of the table, so the second key costs nothing.
 *
 * @internal
 */
final class ConversationOrder
{
	/**
	 * @param array<int, array> $items
	 * @return array<int, array> The freshest conversation first.
	 */
	public static function freshestFirst(array $items): array
	{
		usort(
			$items,
			static fn (array $left, array $right): int => [self::happenedAt($right), (int)$right['ID']]
				<=> [self::happenedAt($left), (int)$left['ID']]
			,
		);

		return $items;
	}

	/**
	 * The moment of the conversation the read answered with, which is not the moment its row appeared:
	 * {@see CommunicationActivityFinder::HAPPENED_AT} says why the two differ.
	 */
	private static function happenedAt(array $item): int
	{
		$moment = $item[CommunicationActivityFinder::HAPPENED_AT] ?? null;

		return $moment instanceof DateTime ? $moment->getTimestamp() : 0;
	}

	private function __construct()
	{
	}
}
