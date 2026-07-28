<?php
namespace Bitrix\ImOpenLines\SilentMode;

/**
 * Per-operator hidden messages (silent) mode state for an open line chat.
 *
 * The state is stored per (user, chat): each operator controls hiding of their own
 * messages only. This is the single access point for the flag - toggling, the message
 * modifier, the connector and serialization all go through this service.
 *
 * Temporary storage is CUserOptions (b_user_option), the key encodes the chat. The
 * permanent solution (a dedicated ORM table per (USER_ID, CHAT_ID)) is introduced by
 * replacing this service implementation without touching consumers.
 */
class PersonalSilentMode
{
	private const OPTION_CATEGORY = 'imopenlines';
	private const OPTION_NAME_PREFIX = 'silent_mode_';
	private const VALUE_ON = 'Y';

	/**
	 * Whether the personal hidden messages mode is enabled for the operator in the chat.
	 * A missing record is treated as "disabled".
	 */
	public static function isEnabled(int $userId, int $chatId): bool
	{
		if ($userId <= 0 || $chatId <= 0)
		{
			return false;
		}

		return \CUserOptions::GetOption(
			self::OPTION_CATEGORY,
			self::getOptionName($chatId),
			'N',
			$userId
		) === self::VALUE_ON;
	}

	/**
	 * Set the operator's personal mode in the chat.
	 * Disabling removes the record to avoid accumulating orphaned flags.
	 */
	public static function set(int $userId, int $chatId, bool $active): void
	{
		if ($userId <= 0 || $chatId <= 0)
		{
			return;
		}

		if ($active)
		{
			\CUserOptions::SetOption(
				self::OPTION_CATEGORY,
				self::getOptionName($chatId),
				self::VALUE_ON,
				false,
				$userId
			);
		}
		else
		{
			\CUserOptions::DeleteOption(
				self::OPTION_CATEGORY,
				self::getOptionName($chatId),
				false,
				$userId
			);
		}
	}

	/**
	 * Reset the personal mode for all users of the chat (session close/finish).
	 */
	public static function resetByChat(int $chatId): void
	{
		if ($chatId <= 0)
		{
			return;
		}

		\CUserOptions::DeleteOptionsByName(
			self::OPTION_CATEGORY,
			self::getOptionName($chatId)
		);
	}

	private static function getOptionName(int $chatId): string
	{
		return self::OPTION_NAME_PREFIX . $chatId;
	}
}
