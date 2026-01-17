<?php

namespace Bitrix\Mail\Helper;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Access\MailAccessController;
use Bitrix\Mail\Access\MailActionDictionary;
use Bitrix\Mail\Access\MailboxAccessController;
use Bitrix\Mail\Access\Permission\PermissionDictionary;
use Bitrix\Mail\Access\Permission\PermissionVariablesDictionary;
use Bitrix\Mail\Integration\Intranet\UserService;
use Bitrix\Mail\Internals\MailboxAccessTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Access\Permission\PermissionDictionary as PermissionDictionaryAlias;
use Bitrix\Main\Engine\CurrentUser;

class MailboxAccess
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
		global $USER;

		$userId = (int)$USER->getId();
		if (!$userId)
		{
			return false;
		}

		if (!$withSharedMailboxes)
		{
			return false;
		}

		return self::isMailboxSharedWithUser($mailboxId, $userId);
	}

	public static function hasCurrentUserAccessOrCanEditMailbox(int $mailboxId): bool
	{
		return
			self::hasCurrentUserAccessToMailbox($mailboxId, withSharedMailboxes: true)
			|| self::hasCurrentUserAccessToEditMailbox($mailboxId)
		;
	}

	public static function hasCurrentUserAccessToMailboxGrid(): bool
	{
		if (!Feature::isMailboxGridAvailable())
		{
			return false;
		}

		global $USER;

		$userId = (int)$USER->getId();
		if (!$userId)
		{
			return false;
		}

		return MailAccessController::can($userId, MailActionDictionary::ACTION_MAILBOX_LIST_VIEW);
	}

	public static function hasCurrentUserAccessToMassConnect(): bool
	{
		if (!Feature::isMailboxGridAvailable())
		{
			return false;
		}

		$userId = CurrentUser::get()->getId();

		if (!$userId)
		{
			return false;
		}

		return MailAccessController::can($userId, MailActionDictionary::ACTION_MAILBOX_MASS_CONNECT_ENTER);
	}

	public static function hasCurrentUserAccessToPermission(): bool
	{
		if (!Feature::isMailboxGridAvailable())
		{
			return false;
		}

		$userId = CurrentUser::get()->getId();

		if (!$userId)
		{
			return false;
		}

		return MailAccessController::can($userId, MailActionDictionary::ACTION_CONFIG_PERMISSIONS_EDIT);
	}

	public static function hasCurrentUserAccessToEditMailbox(int $mailBoxId): bool
	{
		global $USER;

		$userId = (int)$USER->getId();
		if (!$userId)
		{
			return false;
		}

		if (!Feature::isMailboxGridAvailable())
		{
			$mailbox = MailboxTable::getById($mailBoxId)->fetch();

			if (empty($mailbox))
			{
				return false;
			}

			return $userId === (int)($mailbox['USER_ID'] ?? 0) || self::hasAdminAccess();
		}

		return MailboxAccessController::can($userId, MailActionDictionary::ACTION_MAILBOX_LIST_ITEM_EDIT, $mailBoxId);
	}

	public static function getPermissionValue(string $permissionId, ?int $userId = null): ?int
	{
		if ($userId === null)
		{
			$userId = (int)CurrentUser::get()->getId();
		}

		$accessController = MailAccessController::getInstance($userId);
		if ($accessController->getUser()->isAdmin())
		{
			if (PermissionDictionary::getType($permissionId) === PermissionDictionaryAlias::TYPE_TOGGLER)
			{
				return PermissionDictionaryAlias::VALUE_YES;

			}

			return PermissionVariablesDictionary::VARIABLE_ALL;
		}

		return $accessController->getUser()->getPermission($permissionId);
	}

	public static function hasCurrentUserAccessToAddMailbox(): bool
	{
		if (!Feature::isMailboxGridAvailable())
		{
			return true;
		}

		global $USER;

		$userId = (int)$USER->getId();
		if (!$userId)
		{
			return false;
		}

		return MailAccessController::can($userId, MailActionDictionary::ACTION_MAILBOX_SELF_CONNECT);
	}

	public static function hasAdminAccess(): bool
	{
		global $USER;

		return $USER->isAdmin() || $USER->canDoOperation('bitrix24_config');
	}

	/**
	 * @param int $mailboxId
	 * @param array{ID?: string|int, USER_ID?: string|int} $mailboxData
	 *
	 * @return bool
	 */
	public static function hasCurrentUserAccessToEditMailboxAccess(int $mailboxId = 0, array $mailboxData = []): bool
	{
		$userId = (int)CurrentUser::get()->getId();
		if (!$userId || (!$mailboxId && empty($mailboxData)))
		{
			return false;
		}

		$mailboxId = $mailboxId ?: (int)($mailboxData['ID'] ?? 0);
		$ownerId = (int)($mailboxData['USER_ID'] ?? 0);
		if (!$mailboxId || !$ownerId)
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
}
