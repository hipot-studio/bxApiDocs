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

	/**
	 * @param string[] $tags
	 */
	public static function subscribeToTags(array $tags): void
	{
		if (!static::isAvailable())
		{
			return;
		}

		$tags = array_values(array_unique(array_filter(
			array_map(static fn($tag) => is_string($tag) ? trim($tag) : '', $tags),
			static fn(string $tag) => $tag !== '',
		)));
		if (empty($tags))
		{
			return;
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return;
		}

		$extended = \CPullWatch::Extend($userId, $tags);
		if (!is_array($extended))
		{
			$extended = [];
		}

		foreach ($tags as $tag)
		{
			if (($extended[$tag] ?? false) !== true)
			{
				\CPullWatch::Add($userId, $tag);
			}
		}
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