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

	public static function hasCurrentUserAccessToMailbox(int $mailboxId, bool $withSharedMailboxes = false): bool
	{
		if (!$withSharedMailboxes)
		{
			return false;
		}

		return self::isMailboxSharedWithUser($mailboxId, self::getCurrentUserId());
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
			$mailbox = MailboxTable::getById($mailboxId)->fetch();

			return $mailbox && self::isUserMailboxOwnerOrAdminAccess($userId, (int)($mailbox['USER_ID'] ?? 0));
		}

		/** @var MailboxAccessController $controllerClass */
		$controllerClass = static::getAccessControllerClass();

		return $controllerClass::can($userId, MailActionDictionary::ACTION_MAILBOX_LIST_ITEM_EDIT, $mailboxId);
	}

	public static function hasCurrentUserAccessToEditMailboxAccess(int $mailboxId = 0, array $mailboxData = []): bool
	{
		$userId = self::getCurrentUserId();
		$mailboxId = $mailboxId ?: (int)($mailboxData['ID'] ?? 0);
		$ownerId = (int)($mailboxData['USER_ID'] ?? 0);

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

	public static function hasCurrentUserAccessToChangeMailboxOwner(): bool
	{
		return self::hasCurrentUserAdminAccess();
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
