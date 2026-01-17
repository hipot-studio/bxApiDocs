<?php

namespace Bitrix\Mail\Access\Permission;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

class PermissionDictionary extends Main\Access\Permission\PermissionDictionary
{
	public const MAIL_ACCESS_RIGHTS_EDIT = '101';
	public const MAIL_MAILBOX_LIST_ITEM_VIEW = '201';
	public const MAIL_MAILBOX_LIST_ITEM_EDIT = '202';
	public const MAIL_MAILBOX_CONNECT = '203';

	private static function getRephrasedPermissionCode(string $permissionId): ?string
	{
		return match ($permissionId)
		{
			default => null,
		};
	}

	private static function getRephrasedHintCode(string $permissionId): ?string
	{
		return match ($permissionId)
		{
			default => null,
		};
	}

	/**
	 * @param $permissionId int
	 */
	public static function getTitle($permissionId): string
	{
		$rephrasedPermissionCode = self::getRephrasedPermissionCode($permissionId);
		if ($rephrasedPermissionCode)
		{
			return Loc::getMessage($rephrasedPermissionCode) ?? '';
		}

		return parent::getTitle($permissionId) ?? '';
	}

	public static function getHint(int $permissionId): ?string
	{
		$permissionList = self::getList();

		if (!array_key_exists($permissionId, $permissionList))
		{
			return '';
		}

		$rephrasedHintCode = self::getRephrasedHintCode($permissionId);
		$hintCode = $rephrasedHintCode ?? self::HINT_PREFIX . $permissionList[$permissionId]['NAME'];

		return Loc::getMessage($hintCode) ?? '';
	}

	/**
	 * @param string $permissionId
	 */
	public static function getType($permissionId): string
	{
		if (self::isMailboxVariablesPermission($permissionId))
		{
			return static::TYPE_VARIABLES;
		}

		return parent::getType($permissionId);
	}

	public static function getVariables(string $permissionId): array
	{
		if (self::isMailboxVariablesPermission($permissionId))
		{
			return PermissionVariablesDictionary::getMailboxVariables();
		}

		return [];
	}

	public static function isMailboxVariablesPermission(string $permissionId): bool
	{
		return in_array(
			$permissionId,
			[
				self::MAIL_MAILBOX_LIST_ITEM_VIEW,
				self::MAIL_MAILBOX_LIST_ITEM_EDIT,
			],
			true,
		);
	}
}
