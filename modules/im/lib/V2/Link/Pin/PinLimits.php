<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Link\Pin;

/**
 * Single source of truth for pinned-message limits: server-side validation and the section both
 * client platforms read - the web messenger config and the feature set of the mobile application.
 *
 * The two kinds are counted apart: a common pin belongs to the chat, a personal one to the pair
 * of chat and owner. Neither count is narrowed by the history window of the reader, so the quota
 * of a chat cannot be doubled by a member who joined with the history closed.
 */
final class PinLimits
{
	public const MAX_PINS_PER_CHAT = 20;
	public const MAX_PERSONAL_PINS_PER_CHAT = 20;

	private function __construct()
	{
	}

	/**
	 * Both client platforms are served by one assembly, so the field names cannot drift apart.
	 *
	 * @return array{maxPinsPerChat: int, maxPersonalPinsPerChat: int}
	 */
	public static function getClientLimits(): array
	{
		return [
			'maxPinsPerChat' => self::MAX_PINS_PER_CHAT,
			'maxPersonalPinsPerChat' => self::MAX_PERSONAL_PINS_PER_CHAT,
		];
	}

	/**
	 * @param int $pinCount common pins of the whole chat
	 */
	public static function isCommonLimitReached(int $pinCount): bool
	{
		return $pinCount >= self::MAX_PINS_PER_CHAT;
	}

	/**
	 * @param int $pinCount personal pins of one owner in one chat
	 */
	public static function isPersonalLimitReached(int $pinCount): bool
	{
		return $pinCount >= self::MAX_PERSONAL_PINS_PER_CHAT;
	}
}
