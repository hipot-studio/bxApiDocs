<?php
namespace Bitrix\ImOpenLines\SilentMode;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Pull\Event;

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
	/** COMPONENT_ID of a message hidden from the client side of the open line. */
	public const MESSAGE_COMPONENT_ID = 'HiddenMessage';

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

		return self::withMasterOnly(static function () use ($userId, $chatId): bool {
			// Shared user-option caches may have been populated by a lagging replica.
			$option = \CUserOptions::GetList([], [
				'USER_ID' => $userId,
				'CATEGORY' => self::OPTION_CATEGORY,
				'NAME' => self::getOptionName($chatId),
				'COMMON' => 'N',
			])->Fetch();

			return $option && $option['VALUE'] === serialize(self::VALUE_ON);
		});
	}

	/**
	 * Set the operator's personal mode in the chat.
	 * Disabling removes the record to avoid accumulating orphaned flags.
	 *
	 * @return bool false when the state was left unchanged: the caller reports the failure to
	 *              the operator instead of showing a mode that is not stored anywhere.
	 */
	public static function set(int $userId, int $chatId, bool $active): bool
	{
		if ($userId <= 0 || $chatId <= 0)
		{
			return false;
		}

		if ($active)
		{
			$saved = \CUserOptions::SetOption(
				self::OPTION_CATEGORY,
				self::getOptionName($chatId),
				self::VALUE_ON,
				false,
				$userId
			);
		}
		else
		{
			$saved = \CUserOptions::DeleteOption(
				self::OPTION_CATEGORY,
				self::getOptionName($chatId),
				false,
				$userId
			);
		}

		if (!$saved)
		{
			return false;
		}

		self::notify([$userId], $chatId, $active);

		return true;
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

		$userIds = self::withMasterOnly(static function () use ($chatId): array {
			$options = \CUserOptions::GetList([], [
				'CATEGORY' => self::OPTION_CATEGORY,
				'NAME' => self::getOptionName($chatId),
				'COMMON' => 'N',
			]);
			$userIds = [];
			while ($option = $options->Fetch())
			{
				$userId = (int)$option['USER_ID'];
				if ($userId <= 0)
				{
					continue;
				}

				// Deleting exactly what was read leaves a toggle that arrives meanwhile alive, so its
				// owner keeps the mode their own request confirmed, and spares the portal-wide option
				// cache that a delete by name drops on every session close.
				$deleted = \CUserOptions::DeleteOption(
					self::OPTION_CATEGORY,
					self::getOptionName($chatId),
					false,
					$userId
				);
				if ($deleted)
				{
					$userIds[$userId] = $userId;
				}
			}

			return array_values($userIds);
		});

		self::notify($userIds, $chatId, false);
	}

	/**
	 * Personal mode is a privacy decision: a value read from a lagging replica would either hide
	 * a message that is already public or publish one the operator believes hidden.
	 */
	private static function withMasterOnly(\Closure $operation): mixed
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			return $operation();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private static function notify(array $userIds, int $chatId, bool $active): void
	{
		if (!$userIds || !Loader::includeModule('pull'))
		{
			return;
		}

		Event::add($userIds, [
			'module_id' => 'imopenlines',
			'command' => 'updateSilentMode',
			'params' => [
				'chatId' => $chatId,
				'dialogId' => 'chat' . $chatId,
				'silentMode' => $active,
			],
		]);
	}

	private static function getOptionName(int $chatId): string
	{
		return self::OPTION_NAME_PREFIX . $chatId;
	}
}
