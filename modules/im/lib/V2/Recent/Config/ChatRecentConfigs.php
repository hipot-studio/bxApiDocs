<?php

namespace Bitrix\Im\V2\Recent\Config;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Rest\PopupDataItem;

class ChatRecentConfigs implements PopupDataItem
{
	/** @var array<int, ChatRecentConfig> */
	private array $configs = [];

	/**
	 * @param Chat[] $chats
	 * @param bool $withRowWithoutChat adds the mapping for chat id 0 - the sections a row without a chat belongs to
	 */
	public function __construct(array $chats, bool $withRowWithoutChat = false)
	{
		foreach ($chats as $chat)
		{
			$this->configs[(int)$chat->getChatId()] = new ChatRecentConfig($chat);
		}

		if ($withRowWithoutChat)
		{
			$this->configs[0] = ChatRecentConfig::forRowWithoutChat();
		}
	}

	public function merge(PopupDataItem $item): self
	{
		if ($item instanceof self)
		{
			$this->configs += $item->configs;
		}

		return $this;
	}

	public static function getRestEntityName(): string
	{
		return 'recentConfigs';
	}

	public function toRestFormat(array $option = []): ?array
	{
		return array_values(array_map(
			static fn(ChatRecentConfig $config) => $config->toRestFormat($option),
			$this->configs
		));
	}
}
