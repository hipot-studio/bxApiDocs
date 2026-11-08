<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper;

use Bitrix\Mail\Access\MailAccessController;
use Bitrix\Mail\Access\MailActionDictionary;
use Bitrix\Mail\Access\Permission\PermissionDictionary;
use Bitrix\Mail\Access\Permission\PermissionVariablesDictionary;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Main\Access\BaseAccessController;
use Bitrix\Main\Access\Permission\PermissionDictionary as PermissionDictionaryAlias;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;

class MailAccess
{
	public const ERROR_MASS_ACTIONS_DISABLED = 'MASS_CONNECT_DISABLED';
	public const ERROR_MASS_ACTIONS_LICENSE_DENIED = 'MASS_CONNECT_LICENSE_DENIED';
	public const ERROR_MASS_ACTIONS_ACCESS_DENIED = 'MASS_CONNECT_ACCESS_DENIED';

	public static function hasCurrentUserAccessToMailboxGrid(): bool
	{
		return self::checkGridAction(MailActionDictionary::ACTION_MAILBOX_LIST_VIEW);
	}

	/**
	 * Managing employee mailboxes portal-wide: MAIL_MAILBOX_LIST_ITEM_EDIT, deliberately separate
	 * from the right to see the mailbox grid. Mass connect is one of the scenarios behind it.
	 */
	public static function hasCurrentUserAccessToMailboxManagement(): bool
	{
		return self::checkGridAction(MailActionDictionary::ACTION_MAILBOX_MASS_CONNECT_ENTER);
	}

	public static function hasCurrentUserAccessToMassConnect(): bool
	{
		return self::hasCurrentUserAccessToMailboxManagement();
	}

	/**
	 * Both conditions behind mass connect and the flows of it: the tariff feature and the right to
	 * manage employee mailboxes, which hasCurrentUserAccessToMassConnect() answers about alone. The
	 * switch of the grid bulk actions is deliberately not among them.
	 */
	public static function getMassConnectDenial(): ?Error
	{
		if (!LicenseManager::isMailboxesMassConnectEnabled())
		{
			return new Error(
				'Mass mailbox connection is not allowed',
				self::ERROR_MASS_ACTIONS_LICENSE_DENIED,
			);
		}

		if (!self::hasCurrentUserAccessToMassConnect())
		{
			return new Error(
				'Access denied',
				self::ERROR_MASS_ACTIONS_ACCESS_DENIED,
			);
		}

		return null;
	}

	/**
	 * Every condition behind a mass action of the mailbox grid: its own switch on top of everything
	 * mass connect itself asks about.
	 */
	public static function getMassMailboxActionsDenial(): ?Error
	{
		if (!Feature::isMailboxGridBulkActionsAvailable())
		{
			return new Error(
				'Mass mailbox actions are turned off',
				self::ERROR_MASS_ACTIONS_DISABLED,
			);
		}

		return self::getMassConnectDenial();
	}

	public static function hasCurrentUserAccessToMassMailboxActions(): bool
	{
		return self::getMassMailboxActionsDenial() === null;
	}

	public static function hasCurrentUserAccessToSharedSignatureManagement(): bool
	{
		if (!Feature::isSharedSignaturePermissionAvailable())
		{
			return LicenseManager::isMailboxManagementEnabled()
				&& self::hasCurrentUserAccessToMailboxManagement()
			;
		}

		return self::canPerform(MailActionDictionary::ACTION_SHARED_SIGNATURE_MANAGE);
	}

	/**
	 * The acting user is passed explicitly: REST and the AI tool do not always act on behalf of
	 * the session user. While the feature is off, deletion stays allowed for everyone.
	 */
	public static function hasUserAccessToMessageDeletion(?int $userId = null): bool
	{
		if (!Feature::isMessageDeletePermissionAvailable())
		{
			return true;
		}

		return self::canUserPerform(
			MailActionDictionary::ACTION_MESSAGE_DELETE,
			$userId ?? self::getCurrentUserId(),
		);
	}

	/**
	 * The permission is installed on every portal, so while the feature is off it grants nothing:
	 * the section hides it and every write of a sender photo refuses.
	 */
	public static function hasCurrentUserAccessToSenderAvatarManagement(): bool
	{
		return Feature::isSenderAvatarAvailable()
			&& static::canPerform(MailActionDictionary::ACTION_SENDER_AVATAR_MANAGE)
		;
	}

	public static function hasCurrentUserAccessToPermission(): bool
	{
		return self::checkGridAction(MailActionDictionary::ACTION_CONFIG_PERMISSIONS_EDIT);
	}

	public static function hasCurrentUserAccessToConnectMailboxToUser(int $targetUserId): bool
	{
		if ($targetUserId <= 0)
		{
			return false;
		}

		return self::canPerform(
			MailActionDictionary::ACTION_MAILBOX_CONNECT_TO_USER,
			$targetUserId,
		);
	}

	public static function getPermissionValue(string $permissionId, ?int $userId = null): ?int
	{
		$userId ??= self::getCurrentUserId();
		if (!$userId)
		{
			return null;
		}

		$accessController = MailAccessController::getInstance($userId);

		if ($accessController->getUser()->isAdmin())
		{
			return (PermissionDictionary::getType($permissionId) === PermissionDictionaryAlias::TYPE_TOGGLER)
				? PermissionDictionaryAlias::VALUE_YES
				: PermissionVariablesDictionary::VARIABLE_ALL;
		}

		return $accessController->getUser()->getPermission($permissionId);
	}

	public static function hasCurrentUserAdminAccess(): bool
	{
		$user = CurrentUser::get();

		return $user->isAdmin() || $user->canDoOperation('bitrix24_config');
	}

	protected static function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	protected static function checkGridAction(string $action): bool
	{
		if (!Feature::isMailboxGridAvailable())
		{
			return false;
		}

		return self::canPerform($action);
	}

	protected static function canPerform(string $action, ?int $itemId = null): bool
	{
		return self::canUserPerform($action, self::getCurrentUserId(), $itemId);
	}

	protected static function canUserPerform(string $action, int $userId, ?int $itemId = null): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		/** @var BaseAccessController $controllerClass */
		$controllerClass = static::getAccessControllerClass();

		return $controllerClass::can($userId, $action, $itemId);
	}

	protected static function getAccessControllerClass(): string
	{
		return MailAccessController::class;
	}
}
