<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Item\Workgroup\Type;

class ChatType
{
	public static function getChatTypeByGroupTypeAndAccessibility(Type $type, bool $isOpened): string
	{
		return match ($type) {
			Type::Collab => self::getCollabType($isOpened),
			default => self::getChatType(),
		};
	}

	public static function getChatType(): string
	{
		if (!self::isAvailable())
		{
			return '';
		}

		return \Bitrix\Im\V2\Chat::IM_TYPE_CHAT;
	}

	private static function isAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	public static function getCollabType(bool $isOpened): string
	{
		if (!self::isAvailable())
		{
			return '';
		}

		return
			$isOpened
				? \Bitrix\Im\V2\Chat::IM_TYPE_OPEN_COLLAB
				: \Bitrix\Im\V2\Chat::IM_TYPE_COLLAB
		;
	}
}
