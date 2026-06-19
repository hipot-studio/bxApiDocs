<?php

namespace Bitrix\Im\V2\Recent\Config;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Rest\RestConvertible;

class ChatRecentConfigs implements RestConvertible
{
	/** @var ChatRecentConfig[] */
	private array $configs = [];

	/** @param Chat[] $chats */
	public function __construct(array $chats)
	{
		foreach ($chats as $chat)
		{
			$this->configs[] = new ChatRecentConfig($chat);
		}
	}

	public static function getRestEntityName(): string
	{
		return 'recentConfigs';
	}

	public function toRestFormat(array $option = []): ?array
	{
		return array_map(
			static fn(ChatRecentConfig $config) => $config->toRestFormat($option),
			$this->configs
		);
	}
}
