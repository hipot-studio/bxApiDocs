<?php

namespace Bitrix\StaffTrack\Internal\Integration\Main\EventHandler;

use Bitrix\StaffTrack\Internal\Service\Office\AccessRequest\AccessRequestCanceller;

/**
 * The update of a user by the core. A deactivated person loses every request to see the staff of an office
 * and every access granted by one.
 */
class UserEventHandler
{
	private const INACTIVE = 'N';

	/**
	 * Handles main:OnAfterUserUpdate. Every update of every user passes through here, so an update that
	 * deactivates nobody does not reach the storage at all.
	 *
	 * @param array $fields the fields written, with ID and RESULT of the update
	 * @return void
	 */
	public static function onAfterUserUpdate(array $fields): void
	{
		$userId = (int)($fields['ID'] ?? 0);

		if (($fields['ACTIVE'] ?? null) !== self::INACTIVE || !($fields['RESULT'] ?? false) || $userId <= 0)
		{
			return;
		}

		try
		{
			static::createCanceller()->revokeForDeactivated($userId);
		}
		catch (\Throwable $exception)
		{
			static::logFailure(
				__FUNCTION__,
				$exception::class . ' at ' . $exception->getFile() . ':' . $exception->getLine(),
			);
		}
	}

	protected static function createCanceller(): AccessRequestCanceller
	{
		return new AccessRequestCanceller();
	}

	protected static function logFailure(string $operation, string $reason): void
	{
		AddMessage2Log(static::class . '::' . $operation . ' failed: ' . $reason, 'stafftrack');
	}
}
