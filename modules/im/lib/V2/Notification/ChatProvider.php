<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Notification;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Main\ORM\Fields\ExpressionField;

class ChatProvider
{
	private array $userToChat = [];
	private array $chatToUser = [];

	public function prime(int $userId, int $chatId): void
	{
		if ($userId <= 0 || $chatId <= 0)
		{
			return;
		}

		$this->userToChat[$userId] = $chatId;
		$this->chatToUser[$chatId] = $userId;
	}

	public function preload(array $userIds): void
	{
		$userIds = array_diff($userIds, array_keys($this->userToChat));
		if (empty($userIds))
		{
			return;
		}

		$rows = ChatTable::query()
			->setSelect(['MIN_ID' => new ExpressionField('MIN_ID', 'MIN(%s)', ['ID']), 'AUTHOR_ID'])
			->where('TYPE', Chat::IM_TYPE_SYSTEM)
			->whereIn('AUTHOR_ID', $userIds)
			->setGroup(['AUTHOR_ID'])
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$this->prime((int)$row['AUTHOR_ID'], (int)$row['MIN_ID']);
		}

		// cache miss results
		foreach ($userIds as $userId)
		{
			if (!array_key_exists($userId, $this->userToChat))
			{
				$this->userToChat[$userId] = null;
			}
		}
	}

	public function preloadByChatIds(array $chatIds): void
	{
		$chatIds = array_diff($chatIds, array_keys($this->chatToUser));
		if (empty($chatIds))
		{
			return;
		}

		$rows = ChatTable::query()
			->setSelect(['ID', 'AUTHOR_ID'])
			->where('TYPE', Chat::IM_TYPE_SYSTEM)
			->whereIn('ID', $chatIds)
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$this->prime((int)$row['AUTHOR_ID'], (int)$row['ID']);
		}

		// cache miss results
		foreach ($chatIds as $chatId)
		{
			if (!array_key_exists($chatId, $this->chatToUser))
			{
				$this->chatToUser[$chatId] = null;
			}
		}
	}

	public function preloadByNotifications(MessageCollection $messages): void
	{
		$chatIds = $messages->getChatIds();
		$this->preloadByChatIds($chatIds);
	}

	public function filterNotificationMessages(MessageCollection $messages): MessageCollection
	{
		$this->preloadByNotifications($messages);

		return $messages->filter(
			fn($m) => $this->getUserId($m->getChatId()) !== 0
		);
	}

	public function getUserIdsByNotifications(MessageCollection $messages): array
	{
		$chatIds = $messages->getChatIds();
		$this->preloadByChatIds($chatIds);
		$userIds = [];
		foreach ($chatIds as $chatId)
		{
			$userId = $this->getUserId($chatId);
			if ($userId !== 0)
			{
				$userIds[] = $userId;
			}
		}

		return $userIds;
	}

	public function getChatId(int $userId): int
	{
		if (isset($this->userToChat[$userId]))
		{
			return $this->userToChat[$userId];
		}

		$this->preload([$userId]);

		return $this->userToChat[$userId] ?? 0;
	}

	public function getUserId(int $chatId): int
	{
		if (isset($this->chatToUser[$chatId]))
		{
			return $this->chatToUser[$chatId];
		}

		$this->preloadByChatIds([$chatId]);

		return $this->chatToUser[$chatId] ?? 0;
	}
}
