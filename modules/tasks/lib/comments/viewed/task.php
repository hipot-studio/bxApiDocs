<?php

namespace Bitrix\Tasks\Comments\Viewed;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Integration\Forum\Task\UserTopic;
use Bitrix\Tasks\Internals\Counter\Role;
use Bitrix\Tasks\Internals\Registry\UserRegistry;
use Bitrix\Tasks\Internals\Task\ViewedTable;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\Pull;
use Bitrix\Tasks\V2\Internal\Service\Counter;
use Bitrix\Tasks\V2\Internal\Service\ReadAllMessages\ReadAllMessagesQuery;

class Task
{
	/**
	 * @param null $groupId
	 * @param null $userId
	 * @return bool
	 * @throws ArgumentException
	 * @throws ArgumentTypeException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SqlQueryException
	 * @throws SystemException
	 */
	public function readAll($groupId = null, $userId = null, string $role = null): bool
	{
		$currentUserId = (int)CurrentUser::get()->getId();

		$groupId = (int)$groupId;

		$groupCondition = '';
		if ($groupId)
		{
			$groupCondition = "AND TS.GROUP_ID = {$groupId}";
		}

		$userJoin = "INNER JOIN b_tasks_member TM ON TM.TASK_ID = T.ID AND TM.USER_ID = {$currentUserId}";

		$memberRole = null;
		if (
			$role
			&& array_key_exists($role, Role::ROLE_MAP)
		)
		{
			$memberRole = Role::ROLE_MAP[$role];
		}

		if ($memberRole)
		{
			$userJoin .= " AND TM.TYPE = '". $memberRole ."'";
		}

		$this->markAsRead($currentUserId, $userJoin, $groupCondition, $groupId);

		Container::getInstance()->get(Counter\Service::class)->send(
			new Counter\Command\AfterCommentsReadAll(
				userId: $currentUserId,
				groupId: $groupId,
				role: $memberRole,
			),
		);

		Container::getInstance()->get(Pull\Push\Service::class)->send(
			recipients: $currentUserId,
			payload: new Pull\Push\CommentsViewed(
				userId: $currentUserId,
				groupId: $groupId,
				role: $memberRole,
			),
		);

		return true;
	}

	/**
	 * @param null $groupId
	 * @return bool
	 * @throws ArgumentException
	 * @throws ArgumentTypeException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SqlQueryException
	 * @throws SystemException
	 */
	public function readProject($groupId = null)
	{
		$currentUserId = (int)CurrentUser::get()->getId();

		$groupId = (int)$groupId;

		if ($groupId)
		{
			// getConditionByGroupId
			$groupCondition = "AND TS.GROUP_ID = {$groupId}";
		}
		else
		{
			// getConditionByType
			$scrum = UserRegistry::getInstance($currentUserId)->getUserGroups(UserRegistry::MODE_SCRUM);
			$scrumIds = array_keys($scrum);
			$scrumIds[] = 0;
			$groupCondition = "AND TS.GROUP_ID NOT IN (". implode(',', $scrumIds) .")";
		}

		$userJoin = '';

		$this->markAsRead($currentUserId, $userJoin, $groupCondition, $groupId);

		Container::getInstance()->get(Counter\Service::class)->send(
			new Counter\Command\AfterProjectReadAll(
				userId: $currentUserId,
				groupId: $groupId,
			),
		);

		Container::getInstance()->get(Pull\Push\Service::class)->send(
			recipients: $currentUserId,
			payload: new Pull\Push\ProjectCommentsViewed(
				userId: $currentUserId,
				groupId: $groupId,
			),
		);

		return true;
	}

	/**
	 * @param null $groupId
	 * @return bool
	 * @throws ArgumentTypeException
	 * @throws SqlQueryException
	 */
	public function readScrum($groupId = null)
	{
		$currentUserId = (int)CurrentUser::get()->getId();

		$groupId = (int)$groupId;

		if ($groupId)
		{
			$groupCondition = "AND TS.GROUP_ID = {$groupId}";
		}
		else
		{
			$scrum = UserRegistry::getInstance($currentUserId)->getUserGroups(UserRegistry::MODE_SCRUM);
			$scrumIds = array_keys($scrum);
			$scrumIds[] = 0;
			$groupCondition = "AND TS.GROUP_ID IN (". implode(',', $scrumIds) .")";
		}

		$userJoin = '';

		$this->markAsRead($currentUserId, $userJoin, $groupCondition, $groupId);

		Container::getInstance()->get(Counter\Service::class)->send(
			new Counter\Command\AfterScrumReadAll(
				userId: $currentUserId,
				groupId: $groupId,
			),
		);

		Container::getInstance()->get(Pull\Push\Service::class)->send(
			recipients: $currentUserId,
			payload: new Pull\Push\ScrumCommentsViewed(
				userId: $currentUserId,
				groupId: $groupId,
			),
		);
	
		return true;
	}

	/**
	 * @param int $userId
	 * @param string $userJoin
	 * @param string $groupCondition
	 * @throws ArgumentTypeException
	 * @throws SqlQueryException
	 */
	private function markAsRead(int $userId, string $userJoin, string $groupCondition = '', ?int $groupId = null): void
	{
		UserTopic::onReadAll($userId, $userJoin, $groupCondition);
		ViewedTable::readAll($userId, $userJoin, $groupCondition);

		Container::getInstance()->get(ReadAllMessagesQuery::class)->execute(
			userId: $userId,
			groupId: $groupId,
		);
	}
}
