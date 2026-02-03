<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Collector;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Internals\Counter;
use Bitrix\Tasks\Internals\Counter\CounterDictionary;
use Bitrix\Tasks\Internals\Task\MemberTable;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\Im\Service\ImMessageCounterServiceDelegate;

class UserCollector extends Counter\Collector\UserCollector
{
	protected function recountExpired(string|array $taskFilter, array $mutedTasks): array
	{
		if (is_array($taskFilter))
		{
			return parent::recountExpired(parent::getTasksFilter($taskFilter), $mutedTasks);
		}
		else
		{
			return parent::recountExpired($taskFilter, $mutedTasks);
		}
	}

	protected function recountComments(string|array|null $taskFilter, array $mutedTasks): array
	{
		$chats2tasks = Container::getInstance()->getChatRepository()->findChatIdsByTaskIds($taskFilter);

		$groupIds = Container::getInstance()->getGroupRepository()->getGroupIdsByTaskIds($taskFilter);
		$membership = Container::getInstance()->getTaskMemberRepository()->getMembershipForUserIdAndTaskIds($this->userId, $taskFilter);
		$chatsCounters = (new ImMessageCounterServiceDelegate($this->userId))->getForEachChat($chats2tasks);

		$counters = [];

		foreach ($chats2tasks as $taskId => $chatId)
		{
			$value = array_key_exists($chatId, $chatsCounters) ? (int)$chatsCounters[$chatId] : 0;
			if ($value <= 0)
			{
				continue;
			}

			$baseDictionary = in_array($taskId, $mutedTasks) ? CounterDictionary::MAP_MUTED_COMMENTS : CounterDictionary::MAP_COMMENTS;
			$types = array_values(array_intersect($membership[$taskId] ?? [], array_keys($baseDictionary)));

			$counters[] = [
				'USER_ID' => (int)$this->userId,
				'TASK_ID' => (int)$taskId,
				'GROUP_ID' => array_key_exists($taskId, $groupIds) ? (int)$groupIds[$taskId] : 0,
				'TYPE' => $baseDictionary[match (true)
				{
					in_array(MemberTable::MEMBER_TYPE_ORIGINATOR, $types, true) => MemberTable::MEMBER_TYPE_ORIGINATOR,
					in_array(MemberTable::MEMBER_TYPE_RESPONSIBLE, $types, true) => MemberTable::MEMBER_TYPE_RESPONSIBLE,
					in_array(MemberTable::MEMBER_TYPE_ACCOMPLICE, $types, true) => MemberTable::MEMBER_TYPE_ACCOMPLICE,
					in_array(MemberTable::MEMBER_TYPE_AUDITOR, $types, true) => MemberTable::MEMBER_TYPE_AUDITOR,
					default => MemberTable::MEMBER_TYPE_AUDITOR,
				}] ?? CounterDictionary::COUNTER_NEW_COMMENTS,
				'VALUE' => $value,
			];
		}

		return $counters;
	}

	public function getUnReadForumMessageByFilter($filter): array
	{
		$chatIds = Container::getInstance()->getChatRepository()->findChatIdsByTaskIds($this->getTasksFilter($filter['id']));
		$chatCounters = (new ImMessageCounterServiceDelegate($this->userId))->getForEachChat($chatIds);

		$chatCounters = array_values($chatCounters);
		Collection::normalizeArrayValuesByInt($chatCounters, false);

		return $chatCounters;
	}

	protected function getTasksFilter(array $tasksIds): array
	{
		Collection::normalizeArrayValuesByInt($tasksIds);
		return $tasksIds;
	}
}
