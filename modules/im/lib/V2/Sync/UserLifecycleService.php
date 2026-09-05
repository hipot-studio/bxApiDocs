<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Sync;

use Bitrix\Im\V2\Entity\User\User;

/**
 * Handles user lifecycle events (fired, restored, deleted) and delegates
 * them to the sync logger as global events (USER_ID = 0).
 */
class UserLifecycleService
{
	/**
	 * Called from {@see \CIMEvent::OnAfterUserUpdate()}.
	 *
	 * Emits USER_FIRED_EVENT when a user is deactivated (ACTIVE = 'N'),
	 * or USER_RESTORED_EVENT when reactivated (ACTIVE = 'Y').
	 * No event is emitted when the ACTIVE field is absent or has another value.
	 *
	 * @param array $fields Array of changed user fields, must contain 'ID'.
	 */
	public static function onAfterUserUpdate(array $fields): void
	{
		if (!isset($fields['ACTIVE']) || !isset($fields['ID']))
		{
			return;
		}

		$userId = (int)$fields['ID'];
		if ($userId <= 0)
		{
			return;
		}

		if ($fields['ACTIVE'] === 'N')
		{
			$eventName = Event::USER_FIRED_EVENT;
		}
		elseif ($fields['ACTIVE'] === 'Y')
		{
			$eventName = Event::USER_RESTORED_EVENT;
		}
		else
		{
			return;
		}

		// Only real users trigger lifecycle sync events. Service/external accounts
		// (bot, email, imconnector, sale, im_guest, ...) must not bloat b_im_log or
		// the mobile client cache. This load happens only on an ACTIVE change.
		if (!self::isRealUser($userId))
		{
			return;
		}

		$event = new Event($eventName, Event::USER_ENTITY, $userId);
		Logger::getInstance()->addGlobal($event);
	}

	/**
	 * Called from {@see \CIMEvent::OnUserDelete()}.
	 *
	 * Emits COMPLETE_DELETE_EVENT for the deleted user.
	 *
	 * @param int $userId ID of the deleted user.
	 */
	public static function onUserDelete(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		// main:OnUserDelete fires BEFORE the b_user row is removed
		// (see CAllUser::Delete(): OnUserDelete handlers run before
		// "DELETE FROM b_user"), so EXTERNAL_AUTH_ID is still readable here
		// and the reality check is reliable — same criterion as the other handlers.
		if (!self::isRealUser($userId))
		{
			return;
		}

		$event = new Event(Event::COMPLETE_DELETE_EVENT, Event::USER_ENTITY, $userId);
		Logger::getInstance()->addGlobal($event);
	}

	/**
	 * Checks whether the given user is a real (human) user, not a service/external
	 * account (bot, email, imconnector, sale, shop, replica, __controller, call,
	 * document_editor, calendar_sharing, im_guest, rest_system, ...).
	 *
	 * Delegates to {@see User::isInternalType()} with no skip list, so the full
	 * external-type list ({@see \Bitrix\Main\UserTable::getExternalUserTypes()},
	 * including im_guest) is excluded — consistent with the FiredUsersAgent
	 * REAL_USER filter, which also excludes im_guest.
	 */
	private static function isRealUser(int $userId): bool
	{
		return User::getInstance($userId)->isInternalType();
	}
}
