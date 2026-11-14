<?php

namespace Bitrix\Im\V2\Sync\Entity;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\PrivateChat;
use Bitrix\Im\V2\Recent\Config\ChatRecentConfigs;
use Bitrix\Im\V2\Sync\ChatsSync;
use Bitrix\Im\V2\Sync\Recent\RecentSync;
use Bitrix\Im\V2\Sync\Entity;
use Bitrix\Im\V2\Sync\Event;

class Chats implements Entity
{
	private array $chatIds = [];
	private array $messageIds = [];
	private array $recentChatIds = [];
	private array $dialogIds = [];
	private array $shortInfoChatIds = [];
	private array $deletedChatIds = [];
	private array $completeDeleteChatIds = [];
	private array $hiddenHistoryChats = [];
	private array $hiddenHistoryBorders = [];
	private bool $readAll = false;
	private array $chats;
	private RecentSync $recent;

	public function add(Event $event): void
	{
		$entityId = $event->entityId;
		switch ($event->eventName)
		{
			case Event::DELETE_EVENT:
				$this->shortInfoChatIds[$entityId] = $entityId;
				$this->deletedChatIds[$entityId] = $entityId;
				break;
			case Event::COMPLETE_DELETE_EVENT:
				$this->shortInfoChatIds[$entityId] = $entityId;
				$this->completeDeleteChatIds[$entityId] = $entityId;
				break;
			case Event::ADD_EVENT:
				$this->chatIds[$entityId] = $entityId;
				break;
			case Event::READ_ALL_EVENT:
				$this->readAll = true;
				break;
			case Event::DELETE_HISTORY_FOR_SELF_EVENT:
			case Event::DELETE_HISTORY_FOR_ALL_EVENT:
				$this->chatIds[$entityId] = $entityId;
				$this->hiddenHistoryChats[$entityId] = $entityId;
				break;
		}
	}

	private function getRecent(): RecentSync
	{
		$this->getChats();

		if (!isset($this->recent))
		{
			$this->recent = RecentSync::getRecentSync($this->chatIds);

			$entities = $this->recent->getEntityIds();
			$this->recentChatIds = $entities['chatIds'];
			$this->messageIds = $entities['messageIds'];
			$this->dialogIds = $entities['dialogIds'];

			$this->mergeRecentChats();
		}

		return $this->recent;
	}

	private function mergeRecentChats(): void
	{
		foreach ($this->recent as $item)
		{
			$sourceChatId = $item->getSourceChatId();
			if ($sourceChatId <= 0 || isset($this->chats[$sourceChatId]))
			{
				continue;
			}

			$chat = Chat::getInstance($sourceChatId);
			if (
				$chat instanceof Chat\NullChat
				|| $chat instanceof Chat\NotifyChat
			)
			{
				continue;
			}

			$this->fillDialogId($chat);
			$this->chats[$sourceChatId] = $chat;
		}

		Chat::fillSelfRelations($this->chats);
	}

	public function getShortInfoChatIds(): array
	{
		return $this->shortInfoChatIds;
	}

	public function getMessageIds(): array
	{
		return $this->messageIds;
	}

	/**
	 * A cleanup lowers the counter of a chat without an event of its own: the unread rows behind the border
	 * are gone, the ones above it stay. A client that only hears about the border keeps the value it had,
	 * so the chats the window reports as cleared answer with their counter, the way the pull event of the
	 * cleanup does - and they alone: the counters of the whole window are not asked for here, and the
	 * collector they come from is built once per user and kept.
	 */
	public function getChatItems(bool $withCounters = false): ChatsSync
	{
		// getRecent() merges preview-source chats into the chat collection, so it must run before getChats().
		$recent = $this->getRecent();

		return new ChatsSync($this->getChats(), $recent, $withCounters, $this->getHiddenHistoryChatIds());
	}

	public function getRecentConfigs(): ChatRecentConfigs
	{
		return new ChatRecentConfigs($this->getChats());
	}

	private function getChats(): array
	{
		if (isset($this->chats))
		{
			return $this->chats;
		}

		$this->chats = [];
		foreach ($this->chatIds as $chatId)
		{
			$chat = Chat::getInstance((int)$chatId);
			if (
				$chat instanceof Chat\NullChat
				|| $chat instanceof Chat\NotifyChat
			)
			{
				unset($this->chatIds[$chatId]);
			}
			else
			{
				$this->fillDialogId($chat);
				$this->chats[$chatId] = $chat;
			}
		}

		Chat::fillSelfRelations($this->chats);

		return $this->chats;
	}

	private function fillDialogId(Chat $chat): void
	{
		if (!$chat instanceof PrivateChat)
		{
			return;
		}

		$dialogId = $this->dialogIds[$chat->getId()] ?? null;
		if ($dialogId !== null)
		{
			$chat->setDialogId((string)$dialogId);
		}
	}

	/**
	 * @return int[]
	 */
	public function getHiddenHistoryChatIds(): array
	{
		return array_map('intval', array_values($this->hiddenHistoryChats));
	}

	/**
	 * The border of every chat whose history the window announces as cleared, filled by the answer that
	 * knows the requester. Chats left without one keep the border of 0 below.
	 *
	 * @param array<int, int> $bordersByChatId
	 */
	public function setHiddenHistoryBorders(array $bordersByChatId): void
	{
		$this->hiddenHistoryBorders = $bordersByChatId;
	}

	/**
	 * Chats dropped by getChats() (deleted or notify feeds) have no dialog to point at,
	 * and they are absent from the payload anyway.
	 */
	private function getHiddenHistoryDialogIds(): array
	{
		$result = [];
		foreach ($this->hiddenHistoryChats as $chatId)
		{
			$dialogId = ($this->chats[(int)$chatId] ?? null)?->getDialogId();
			if ($dialogId !== null)
			{
				$result[] = $dialogId;
			}
		}

		return $result;
	}

	/**
	 * The same chats hiddenHistoryChats names, each with the last message the server has hidden from the
	 * requester; 0 stands for a border the answer could not tell. The client that keeps a history of its
	 * own drops everything up to it instead of guessing the cut from the recent row.
	 */
	private function getHiddenHistoryBordersByDialogId(): array
	{
		$result = [];
		foreach ($this->hiddenHistoryChats as $chatId)
		{
			$dialogId = ($this->chats[(int)$chatId] ?? null)?->getDialogId();
			if ($dialogId !== null)
			{
				$result[$dialogId] = (int)($this->hiddenHistoryBorders[(int)$chatId] ?? 0);
			}
		}

		return $result;
	}

	public static function getRestEntityName(): string
	{
		return 'chatSync';
	}

	public function toRestFormat(array $option = []): ?array
	{
		$this->getRecent();

		$result = [
			'addedRecent' => $this->recentChatIds,
			'addedChats' => $this->chatIds,
			'deletedChats' => $this->deletedChatIds,
			'completeDeletedChats' => $this->completeDeleteChatIds,
			'hiddenHistoryChats' => $this->getHiddenHistoryDialogIds(),
			'hiddenHistoryBorders' => $this->getHiddenHistoryBordersByDialogId(),
		];

		if ($this->readAll)
		{
			$result['readAllChats'] = true;
		}

		return $result;
	}
}
