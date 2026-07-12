<?php

namespace Bitrix\Mail\Helper;

use Bitrix\Mail\Access\MailActionDictionary;
use Bitrix\Mail\Access\MailboxAccessController;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Integration\Intranet\UserService;
use Bitrix\Mail\Internals\MailboxAccessTable;
use Bitrix\Mail\MailboxTable;

class MailboxAccess extends MailAccess
{
	public static function isMailboxOwner(int $mailboxId, int $userId): bool
	{
		$query = MailboxTable::query()
			->setSelect(['ID'])
			->where('ID', $mailboxId)
			->where('USER_ID', $userId)
			->setLimit(1)
		;

		return (bool)$query->fetch();
	}

	public static function isMailboxSharedWithUser(int $mailboxId, int $userId): bool
	{
		$accessCodes = \CAccess::GetUserCodesArray($userId) ?? [];
		if (empty($accessCodes))
		{
			return false;
		}

		$query = MailboxAccessTable::query()
			->setSelect(['ID'])
			->where('MAILBOX_ID', $mailboxId)
			->whereIn('ACCESS_CODE', $accessCodes)
			->setLimit(1)
		;

		return (bool)$query->fetch();
	}

	public static function hasUserAccessToMailbox(int $mailboxId, int $userId, bool $withSharedMailboxes = false): bool
	{
		if ($withSharedMailboxes && self::isMailboxSharedWithUser($mailboxId, $userId))
		{
			return true;
		}

		return self::isMailboxOwner($mailboxId, $userId);
	}

	public static function hasCurrentUserAccessToMailbox(int $mailboxId, bool $withSharedMailboxes = false): bool
	{
		return self::hasUserAccessToMailbox($mailboxId, self::getCurrentUserId(), $withSharedMailboxes);
	}

	public static function hasCurrentUserAnyAccessToMailbox(int $mailboxId): bool
	{
		return
			self::hasCurrentUserAccessToMailbox($mailboxId, withSharedMailboxes: true)
			|| self::hasCurrentUserAccessToEditMailbox($mailboxId)
		;
	}

	public static function hasCurrentUserAccessToEditMailbox(int $mailboxId): bool
	{
		$userId = self::getCurrentUserId();
		if (!$userId)
		{
			return false;
		}

		if (!Feature::isMailboxGridAvailable())
		{
			$ownerId = MailboxTable::getOwnerId($mailboxId);

			return $ownerId > 0 && self::isUserMailboxOwnerOrAdminAccess($userId, $ownerId);
		}

		/** @var MailboxAccessController $controllerClass */
		$controllerClass = static::getAccessControllerClass();

		return $controllerClass::can($userId, MailActionDictionary::ACTION_MAILBOX_LIST_ITEM_EDIT, $mailboxId);
	}

	public static function hasCurrentUserAccessToEditMailboxAccess(int $mailboxId, int $ownerId): bool
	{
		$userId = self::getCurrentUserId();

		if (!$userId || !$mailboxId || !$ownerId)
		{
			return false;
		}

		if ($userId === $ownerId)
		{
			return true;
		}

		if (!self::hasCurrentUserAccessToEditMailbox($mailboxId))
		{
			return false;
		}

		return self::isMailboxSharedWithUser($mailboxId, $userId) || UserService::isUserFired($ownerId);
	}

	public static function hasCurrentUserAccessToAddMailbox(): bool
	{
		if (!Feature::isMailboxGridAvailable())
		{
			return true;
		}

		return self::canPerform(MailActionDictionary::ACTION_MAILBOX_SELF_CONNECT);
	}

	public static function hasCurrentUserAccessToEditMailboxIntegrationCrm(): bool
	{
		return self::canPerform(MailActionDictionary::ACTION_MAILBOX_INTEGRATION_CRM_EDIT);
	}

	public static function hasCurrentUserAccessToViewMailboxIntegrationCrm(): bool
	{
		return \Bitrix\Mail\Integration\Crm\Permissions::getInstance()->hasAccessToCrm();
	}

	public static function hasCurrentUserAccessToChangeMailboxOwner(int $mailboxId): bool
	{
		if (!self::hasCurrentUserAdminAccess())
		{
			return false;
		}

		$mailbox = MailboxTable::query()
			->setSelect(['ID', 'SERVER_TYPE', 'PASSWORD', 'OPTIONS'])
			->where('ID', $mailboxId)
			->setLimit(1)
			->fetch()
		;

		if (!$mailbox)
		{
			return false;
		}

		return OrphanedMailboxLifecycle::isOrphan($mailbox);
	}

	protected static function getAccessControllerClass(): string
	{
		return MailboxAccessController::class;
	}

	private static function isUserMailboxOwnerOrAdminAccess(int $userId, int $mailboxOwnerId): bool
	{
		return $userId === $mailboxOwnerId || self::hasCurrentUserAdminAccess();
	}
}
