<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Adapter;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Reading\Counter\Entity\ChatCounter;
use Bitrix\Im\V2\Reading\Counter\Entity\UserCounters;

/**
 * Adapts new UserCounters to legacy CounterService format.
 * Returns the most complete format for all consumers.
 */
final class LegacyCountersAdapter
{
	public function toArray(UserCounters $userCounters): array
	{
		return $this->convert($userCounters, []);
	}

	public function toArrayWithDialogs(UserCounters $userCounters, int $userId): array
	{
		$privateChatIds = $this->collectPrivateChatIds($userCounters);
		$chatIdToDialogId = $this->getDialogIdMapping($privateChatIds, $userId);

		return $this->convert($userCounters, $chatIdToDialogId);
	}

	private function convert(UserCounters $userCounters, array $chatIdToDialogId): array
	{
		$result = $this->getDefaultResult($userCounters);

		foreach ($userCounters as $chatCounter)
		{
			$this->processChatCounter($chatCounter, $chatIdToDialogId, $result);
		}

		$this->finalizeResult($result);

		return $result;
	}

	private function getDefaultResult(UserCounters $userCounters): array
	{
		return [
			'TYPE' => [
				'ALL' => 0,
				'NOTIFY' => $userCounters->getNotificationCounter(),
				'CHAT' => 0,
				'LINES' => 0,
				'DIALOG' => 0,
				'COPILOT' => 0,
				'COLLAB' => 0,
				'MESSENGER' => 0,
				'TASKS_TASK' => 0,
			],
			'CHAT' => [],
			'DIALOG' => [],
			'COLLAB' => [],
			'CHAT_MUTED' => [],
			'CHAT_UNREAD' => [],
			'DIALOG_UNREAD' => [],
			'COLLAB_UNREAD' => [],
			'COPILOT_UNREAD' => [],
			'TASKS_TASK_UNREAD' => [],
			'LINES' => [],
			'COPILOT' => [],
			'CHANNEL_COMMENT' => [],
			'TASKS_TASK' => [],
		];
	}

	private function collectPrivateChatIds(UserCounters $userCounters): array
	{
		$privateChatIds = [];
		foreach ($userCounters as $counter)
		{
			if ($counter->type->literal === \IM_MESSAGE_PRIVATE)
			{
				$privateChatIds[] = $counter->chatId;
			}
		}
		return $privateChatIds;
	}

	private function getDialogIdMapping(array $privateChatIds, int $userId): array
	{
		if (empty($privateChatIds))
		{
			return [];
		}

		$rows = RelationTable::query()
			->setSelect(['USER_ID', 'CHAT_ID'])
			->whereNot('USER_ID', $userId)
			->whereIn('CHAT_ID', $privateChatIds)
			->fetchAll()
		;

		$map = [];
		foreach ($rows as $row)
		{
			$map[(int)$row['CHAT_ID']] = (int)$row['USER_ID'];
		}

		return $map;
	}

	private function processChatCounter(ChatCounter $counter, array $chatIdToDialogId, array &$result): void
	{
		$chatId = $counter->chatId;

		if ($counter->parentChatId > 0)
		{
			$this->processChannelComment($counter, $result);
			return;
		}

		$dialogId = $chatIdToDialogId[$chatId] ?? null;
		if ($dialogId !== null)
		{
			$this->processDialogCounter($counter, $dialogId, $result);
			return;
		}

		$this->processRegularChatCounter($counter, $result);
	}

	private function processChannelComment(ChatCounter $counter, array &$result): void
	{
		$chatId = $counter->chatId;

		if ($counter->isMuted)
		{
			if ($counter->counter > 0)
			{
				$result['CHAT_MUTED'][$chatId] = $counter->counter;
			}
			return;
		}

		if ($counter->counter <= 0)
		{
			return;
		}

		$result['CHANNEL_COMMENT'][$counter->parentChatId][$chatId] = $counter->counter;
		$result['TYPE']['MESSENGER'] += $counter->counter;
	}

	private function processDialogCounter(ChatCounter $counter, int $dialogId, array &$result): void
	{
		$chatId = $counter->chatId;

		if ($counter->isMarkedAsUnread)
		{
			$result['DIALOG_UNREAD'][$dialogId] = $dialogId;
		}

		if ($counter->isMuted)
		{
			if ($counter->counter > 0)
			{
				$result['CHAT_MUTED'][$chatId] = $counter->counter;
			}
			return;
		}

		if ($counter->counter > 0)
		{
			$result['DIALOG'][$dialogId] = $counter->counter;
			$result['TYPE']['DIALOG'] += $counter->counter;
			$result['TYPE']['MESSENGER'] += $counter->counter;
			return;
		}

		if ($counter->isMarkedAsUnread)
		{
			$result['TYPE']['DIALOG']++;
			$result['TYPE']['MESSENGER']++;
		}
	}

	private function processRegularChatCounter(ChatCounter $counter, array &$result): void
	{
		$chatId = $counter->chatId;

		$inChat = false;
		$inCopilot = false;
		$inCollab = false;
		$inLines = false;
		$inTasksTask = false;

		foreach ($counter->recentSections as $section)
		{
			switch ($section)
			{
				case 'default':
				case 'openChannel':
					$inChat = true;
					break;

				case 'copilot':
					$inCopilot = true;
					$inChat = true;
					break;

				case 'collab':
					$inCollab = true;
					$inChat = true;
					break;

				case 'lines':
					$inLines = true;
					break;

				case 'tasksTask':
					$inTasksTask = true;
					break;
			}
		}

		if ($counter->isMarkedAsUnread)
		{
			if ($inChat)
			{
				$result['CHAT_UNREAD'][$chatId] = $chatId;
			}
			if ($inCopilot)
			{
				$result['COPILOT_UNREAD'][$chatId] = $chatId;
			}
			if ($inCollab)
			{
				$result['COLLAB_UNREAD'][$chatId] = $chatId;
			}
			if ($inTasksTask)
			{
				$result['TASKS_TASK_UNREAD'][$chatId] = $chatId;
			}
		}

		if ($counter->isMuted)
		{
			if ($counter->counter > 0)
			{
				$result['CHAT_MUTED'][$chatId] = $counter->counter;
			}
			return;
		}

		if ($counter->counter > 0)
		{
			$count = $counter->counter;

			if ($inChat)
			{
				$result['CHAT'][$chatId] = $count;
				$result['TYPE']['CHAT'] += $count;
			}
			if ($inCopilot)
			{
				$result['COPILOT'][$chatId] = $count;
				$result['TYPE']['COPILOT'] += $count;
			}
			if ($inCollab)
			{
				$result['COLLAB'][$chatId] = $count;
				$result['TYPE']['COLLAB'] += $count;
			}
			if ($inLines)
			{
				$result['LINES'][$chatId] = $count;
				$result['TYPE']['LINES'] += $count;
			}
			if ($inTasksTask)
			{
				$result['TASKS_TASK'][$chatId] = $count;
				$result['TYPE']['TASKS_TASK'] += $count;
			}

			if ($inChat || $inLines || $inTasksTask)
			{
				$result['TYPE']['MESSENGER'] += $count;
			}

			return;
		}

		if ($counter->isMarkedAsUnread)
		{
			$countsInTypes = false;

			if ($inChat)
			{
				$result['TYPE']['CHAT']++;
				$countsInTypes = true;
			}
			if ($inCopilot)
			{
				$result['TYPE']['COPILOT']++;
				$countsInTypes = true;
			}
			if ($inCollab)
			{
				$result['TYPE']['COLLAB']++;
				$countsInTypes = true;
			}
			if ($inTasksTask)
			{
				$result['TYPE']['TASKS_TASK']++;
				$countsInTypes = true;
			}

			if ($countsInTypes && ($inChat || $inTasksTask))
			{
				$result['TYPE']['MESSENGER']++;
			}
		}
	}

	private function finalizeResult(array &$result): void
	{
		$result['TYPE']['ALL'] = $result['TYPE']['MESSENGER'] + $result['TYPE']['NOTIFY'];

		$result['CHAT_UNREAD'] = array_values($result['CHAT_UNREAD']);
		$result['DIALOG_UNREAD'] = array_values($result['DIALOG_UNREAD']);
		$result['COPILOT_UNREAD'] = array_values($result['COPILOT_UNREAD']);
		$result['COLLAB_UNREAD'] = array_values($result['COLLAB_UNREAD']);
		$result['TASKS_TASK_UNREAD'] = array_values($result['TASKS_TASK_UNREAD']);
	}
}
