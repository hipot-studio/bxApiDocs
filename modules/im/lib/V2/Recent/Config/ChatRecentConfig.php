<?php

namespace Bitrix\Im\V2\Recent\Config;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Rest\PopupDataItem;
use Bitrix\Main\DI\ServiceLocator;

class ChatRecentConfig implements PopupDataItem
{
	// A null chat is a row the client shows without a chat behind it: a portal acquaintance or a
	// birthday greeting. Such a row is still placed into sections by the client, under chat id 0.
	private ?Chat $chat;

	public function __construct(?Chat $chat = null)
	{
		$this->chat = $chat;
	}

	public static function forRowWithoutChat(): self
	{
		return new self();
	}

	public function merge(PopupDataItem $item): PopupDataItem
	{
		return $this;
	}

	public static function getRestEntityName(): string
	{
		return 'recentConfig';
	}

	public function toRestFormat(array $option = []): ?array
	{
		return [
			'chatId' => $this->getChatId(),
			'sections' => $this->getSections(),
		];
	}

	public function toPullFormat(): array
	{
		return [
			'chatId' => $this->getChatId(),
			'sections' => $this->chat === null ? $this->getSections() : $this->chat->getRecentSections(),
		];
	}

	private function getChatId(): int
	{
		return (int)$this->chat?->getChatId();
	}

	private function getSections(): array
	{
		if ($this->chat === null)
		{
			// The sections a dialog with that person would get, counted by the same manager.
			return RecentConfigManager::getInstance()->getBaseRecentSections($this->getPrivateChatType());
		}

		if ($this->chat->getRole() === Chat::ROLE_GUEST)
		{
			return $this->chat->getRecentSectionsForGuest();
		}

		return $this->chat->getRecentSections();
	}

	private function getPrivateChatType(): Chat\Type
	{
		return ServiceLocator::getInstance()
			->get(TypeRegistry::class)
			->getByLiteralAndEntity(Chat::IM_TYPE_PRIVATE, null)
		;
	}
}