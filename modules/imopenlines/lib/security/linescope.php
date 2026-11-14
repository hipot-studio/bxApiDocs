<?php

namespace Bitrix\ImOpenlines\Security;

use Bitrix\ImOpenLines\Model\QueueTable;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;

/**
 * The owner of the rule which lines a selection of a list answers the user with.
 *
 * The same rule has two more implementations: Config::canDoOperation(), behind the pointwise checks
 * canViewLine()/canEditLine(), and V2\Statistics\AccessGate for the VOTE_HEAD entity. This one and
 * canDoOperation() match the queue of the line against every allowed user and the owner of the line
 * against the user alone, so the two answer alike on every level, the level "department" included.
 * AccessGate answers about another entity and keeps a rule of its own.
 */
class LineScope
{
	private static array $cacheAllowedUserIds = [];

	/**
	 * Returns the condition selecting the lines the user may act on, or null when the user is not limited.
	 * @param int $userId User's id.
	 * @param string $actionCode Code of the action on Permissions::ENTITY_LINES.
	 * @return ConditionTree|null Null means no restrictions at all.
	 * @throws SystemException When the user has no permission on lines.
	 */
	public static function buildCondition(int $userId, string $actionCode): ?ConditionTree
	{
		$allowed = self::getAllowedUserIds($userId, $actionCode);

		if (!is_array($allowed))
		{
			return null;
		}

		if (empty($allowed))
		{
			// ConditionTree::whereIn() drops an empty set of values, so the condition would select every line
			throw new SystemException('The user has no permission on lines, the operation should have been denied');
		}

		$queueSubquery = QueueTable::query()
			->setSelect(['CONFIG_ID'])
			->whereIn('USER_ID', $allowed)
		;

		return Query::filter()
			->logic(ConditionTree::LOGIC_OR)
			->whereIn('ID', $queueSubquery)
			->where('MODIFY_USER_ID', $userId)
		;
	}

	/**
	 * Returns the user ids whose lines are available to the user, memoized for the request.
	 * @param int $userId User's id.
	 * @param string $actionCode Code of the action on Permissions::ENTITY_LINES.
	 * @return int[]|null Null means no restrictions at all.
	 */
	private static function getAllowedUserIds(int $userId, string $actionCode): ?array
	{
		// null is a meaningful answer here, so a miss cannot be told by isset()
		if (array_key_exists($actionCode, self::$cacheAllowedUserIds[$userId] ?? []))
		{
			return self::$cacheAllowedUserIds[$userId][$actionCode];
		}

		$permissions = Permissions::createWithUserId($userId);
		$allowed = Helper::getAllowedUserIds(
			$userId,
			$permissions->getPermission(Permissions::ENTITY_LINES, $actionCode)
		);

		if (is_array($allowed))
		{
			// the set goes into SQL as the values of an IN(), so it is kept deduplicated and numeric
			$allowed = array_values(array_unique(array_map('intval', $allowed)));
		}

		self::$cacheAllowedUserIds[$userId][$actionCode] = $allowed;

		return $allowed;
	}
}
