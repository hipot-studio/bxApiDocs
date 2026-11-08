<?php

namespace Bitrix\Mail\Helper;

use Bitrix\Mail\Access\MailActionDictionary;
use Bitrix\Mail\Access\MailboxAccessController;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Integration\Intranet\UserService;
use Bitrix\Mail\Internals\MailboxAccessTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class MailboxAccess extends MailAccess
{
	private static array $batchMailboxes = [];
	private static array $batchSharedMailboxIds = [];

	public static function withMailboxBatch(array $models, int $userId, callable $check): mixed
	{
		$previous = [self::$batchMailboxes, self::$batchSharedMailboxIds];
		self::$batchMailboxes = [$userId => $models];
		self::$batchSharedMailboxIds = [];
		try
		{
			return $check();
		}
		finally
		{
			[self::$batchMailboxes, self::$batchSharedMailboxIds] = $previous;
		}
	}

	public static function isMailboxOwner(int $mailboxId, int $userId): bool
	{
		if (isset(self::$batchMailboxes[$userId][$mailboxId]))
		{
			return self::$batchMailboxes[$userId][$mailboxId]->getOwnerId() === $userId;
		}

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
		if (isset(self::$batchMailboxes[$userId][$mailboxId]))
		{
			self::$batchSharedMailboxIds[$userId] ??= array_fill_keys(
				self::filterSharedMailboxIds(array_keys(self::$batchMailboxes[$userId]), $userId),
				true,
			);

			return isset(self::$batchSharedMailboxIds[$userId][$mailboxId]);
		}

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

	/**
	 * @param int[] $mailboxIds
	 * @return int[]
	 */
	public static function filterAccessibleMailboxIds(array $mailboxIds, int $userId): array
	{
		$mailboxIds = array_values(array_unique(array_filter(array_map('intval', $mailboxIds))));
		if ($mailboxIds === [] || $userId <= 0)
		{
			return [];
		}

		$ownedRows = MailboxTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $mailboxIds)
			->where('USER_ID', $userId)
			->fetchAll()
		;
		$accessible = array_fill_keys(array_map('intval', array_column($ownedRows, 'ID')), true);
		$accessCodes = \CAccess::GetUserCodesArray($userId) ?? [];
		if ($accessCodes !== [])
		{
			$sharedRows = MailboxAccessTable::query()
				->setSelect(['MAILBOX_ID'])
				->whereIn('MAILBOX_ID', $mailboxIds)
				->whereIn('ACCESS_CODE', $accessCodes)
				->fetchAll()
			;
			foreach ($sharedRows as $row)
			{
				$accessible[(int)$row['MAILBOX_ID']] = true;
			}
		}

		return array_keys($accessible);
	}

	/**
	 * @return int[]
	 * @throws SystemException
	 */
	public static function resolveUserMailboxIds(
		?int $mailboxId,
		int $userId,
		bool $withSharedMailboxes = true
	): array
	{
		if ($mailboxId === null)
		{
			return array_map('intval', array_keys(MailboxTable::getUserMailboxes($userId)));
		}

		if ($mailboxId <= 0)
		{
			throw new SystemException('mailboxId must be a positive integer.');
		}

		if (!self::hasUserAccessToMailbox($mailboxId, $userId, $withSharedMailboxes))
		{
			throw new SystemException('The selected mailbox is not available to the current user.');
		}

		return [$mailboxId];
	}

	/**
	 * @param int $ownerId
	 * @param int $userId
	 * @param bool $withSharedMailboxes
	 * @return int[]
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function getAccessibleOwnerMailboxIds(
		int $ownerId,
		int $userId,
		bool $withSharedMailboxes = true
	): array
	{
		if ($ownerId <= 0 || $userId <= 0)
		{
			return [];
		}

		$ownedMailboxIds = array_map('intval', array_keys(MailboxTable::getTheOwnersMailboxes($ownerId, true)));
		if ($ownedMailboxIds === [])
		{
			return [];
		}

		if ($ownerId === $userId)
		{
			return $ownedMailboxIds;
		}

		if (!$withSharedMailboxes)
		{
			return [];
		}

		return self::filterSharedMailboxIds($ownedMailboxIds, $userId);
	}

	/**
	 * @param int[] $mailboxIds
	 * @param int $userId
	 * @return int[]
	 * @throws SystemException
	 * @throws ObjectPropertyException
	 */
	private static function filterSharedMailboxIds(array $mailboxIds, int $userId): array
	{
		$accessCodes = \CAccess::GetUserCodesArray($userId) ?? [];
		if ($accessCodes === [])
		{
			return [];
		}

		$rows = MailboxAccessTable::query()
			->setSelect(['MAILBOX_ID'])
			->whereIn('MAILBOX_ID', $mailboxIds)
			->whereIn('ACCESS_CODE', $accessCodes)
			->fetchAll()
		;

		$accessibleMailboxIds = array_fill_keys(array_map('intval', array_column($rows, 'MAILBOX_ID')), true);

		return array_values(array_filter(
			$mailboxIds,
			static fn (int $mailboxId): bool => isset($accessibleMailboxIds[$mailboxId]),
		));
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
		return self::hasUserAccessToEditMailboxIntegrationCrm(self::getCurrentUserId());
	}

	public static function hasUserAccessToEditMailboxIntegrationCrm(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		/** @var MailboxAccessController $controllerClass */
		$controllerClass = static::getAccessControllerClass();

		return $controllerClass::can($userId, MailActionDictionary::ACTION_MAILBOX_INTEGRATION_CRM_EDIT);
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
