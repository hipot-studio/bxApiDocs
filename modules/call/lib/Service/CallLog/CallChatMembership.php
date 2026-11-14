<?php

declare(strict_types=1);

namespace Bitrix\Call\Service\CallLog;

use Bitrix\Call\Model\CallTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * Which of the users are members of the chat of which of the calls right now - the predicate the roster
 * of a row of the journal goes out by.
 *
 * One builder for every reader of that predicate: the warm-up of the roster of a page asks self::query()
 * and the search by a participant self::callsOfMemberQuery(), both over the one join of the members of the
 * chat, so the list and the search cannot come to disagree on who may read the roster of a call. A call
 * without a chat matches nobody.
 *
 * Module im is the caller's to include: the relations are its table, and a caller without it has its
 * own answer to give rather than an error of this class.
 */
final class CallChatMembership
{
	/**
	 * Rows of ID (the call) and MEMBER_ID (the user), one per pair that holds.
	 *
	 * Both lists are required to be non-empty: an empty whereIn() is dropped from the query rather than
	 * matching nothing, which would turn the question about a few calls into one about all of them.
	 *
	 * @param int[] $callIds
	 * @param int[] $userIds
	 * @throws ArgumentException When either list is empty.
	 */
	public static function query(array $callIds, array $userIds): Query
	{
		if ($callIds === [])
		{
			throw new ArgumentException('The calls of a membership query must be named.', 'callIds');
		}
		if ($userIds === [])
		{
			throw new ArgumentException('The users of a membership query must be named.', 'userIds');
		}

		return CallTable::query()
			->setSelect(['ID', 'MEMBER_ID' => 'RELATION.USER_ID'])
			->registerRuntimeField('RELATION', self::relationsOfTheChat())
			->whereIn('ID', $callIds)
			->whereIn('RELATION.USER_ID', $userIds)
		;
	}

	/**
	 * The calls whose chat holds the user right now, as a query of their ids.
	 *
	 * The form the predicate takes as a condition on the call of a row: the query it is put into names the call, so
	 * no list of calls is read ahead of it, and the answer is the same as self::query() gives for each of those calls.
	 */
	public static function callsOfMemberQuery(int $userId): Query
	{
		return CallTable::query()
			->setSelect(['ID'])
			->registerRuntimeField('RELATION', self::relationsOfTheChat())
			->where('RELATION.USER_ID', $userId)
		;
	}

	/**
	 * The members of the chat of the call; a call without a chat has none.
	 */
	private static function relationsOfTheChat(): Reference
	{
		return new Reference(
			'RELATION',
			RelationTable::class,
			Join::on('this.CHAT_ID', 'ref.CHAT_ID'),
			['join_type' => Join::TYPE_INNER],
		);
	}
}
