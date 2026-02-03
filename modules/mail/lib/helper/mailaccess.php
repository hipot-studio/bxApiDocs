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

class MailAccess
{
	public static function hasCurrentUserAccessToMailboxGrid(): bool
	{
		return self::checkGridAction(MailActionDictionary::ACTION_MAILBOX_LIST_VIEW);
	}

	public static function hasCurrentUserAccessToMassConnect(): bool
	{
		return self::checkGridAction(MailActionDictionary::ACTION_MAILBOX_MASS_CONNECT_ENTER);
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
				: PermissionVariablesDictionary::VARIABLE_ALL
			;
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
		$userId = self::getCurrentUserId();
		if (!$userId)
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
