<?php
namespace Bitrix\StaffTrack\Internal\Integration\Pull;

use Bitrix\Main\Loader;
use Bitrix\Main\Engine\CurrentUser;

class PushSender
{
	public const MODULE_NAME = 'stafftrack';

	public static function send(int $userId, PushCommand $command, array $params): void
	{
		if (!static::isAvailable())
		{
			return;
		}

		\Bitrix\Pull\Event::add(
			$userId,
			[
				'module_id' => self::MODULE_NAME,
				'command' => $command->value,
				'params' => $params,
			],
		);
	}

	public static function subscribeToTag(string $tag): void
	{
		if (!static::isAvailable())
		{
			return;
		}

		\CPullWatch::Add(CurrentUser::get()->getId(), $tag, true);
	}

	public static function sendByTag(string $tag, PushCommand $command, array $params): void
	{
		if (!static::isAvailable())
		{
			return;
		}

		\CPullWatch::AddToStack(
			$tag,
			[
				'module_id' => self::MODULE_NAME,
				'command' => $command->value,
				'params' => $params,
			],
		);
	}

	public static function isAvailable(): bool
	{
		return Loader::includeModule('pull');
	}
}