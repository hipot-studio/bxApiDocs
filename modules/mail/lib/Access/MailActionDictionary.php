<?php

namespace Bitrix\Mail\Access;

use Bitrix\Mail\Access\Permission\PermissionDictionary;
use ReflectionClass;

final class MailActionDictionary
{
	public const ACTION_CONFIG_PERMISSIONS_EDIT = 'ACTION_CONFIG_PERMISSIONS_EDIT';
	public const ACTION_MAILBOX_LIST_VIEW = 'ACTION_MAILBOX_LIST_VIEW';
	public const ACTION_MAILBOX_LIST_ITEM_VIEW = 'ACTION_MAILBOX_LIST_ITEM_VIEW';
	public const ACTION_MAILBOX_LIST_ITEM_EDIT = 'ACTION_MAILBOX_LIST_ITEM_EDIT';
	public const ACTION_MAILBOX_SELF_CONNECT = 'ACTION_MAILBOX_SELF_CONNECT';
	public const ACTION_MAILBOX_CONNECT_TO_USER = 'ACTION_MAILBOX_CONNECT_TO_USER';
	public const ACTION_MAILBOX_MASS_CONNECT_ENTER = 'ACTION_MAILBOX_MASS_CONNECT_ENTER';
	public const ACTION_MAILBOX_INTEGRATION_CRM_EDIT = 'ACTION_MAILBOX_INTEGRATION_CRM_EDIT';

	public const PREFIX = "ACTION_";

	public static function getActionName(string $value): ?string
	{
		$constants = self::getActionNames();
		if (!array_key_exists($value, $constants))
		{
			return null;
		}

		return str_replace(self::PREFIX, '', $constants[$value]);
	}

	private static function getActionNames(): array
	{
		$class = new ReflectionClass(__CLASS__);
		$constants = $class->getConstants();
		foreach ($constants as $name => $value)
		{
			if (!str_starts_with($name, self::PREFIX))
			{
				unset($constants[$name]);
			}
		}

		return array_flip($constants);
	}

	public static function getActionPermissionMap(): array
	{
		return [
			self::ACTION_CONFIG_PERMISSIONS_EDIT => PermissionDictionary::MAIL_ACCESS_RIGHTS_EDIT,
			self::ACTION_MAILBOX_LIST_ITEM_EDIT => PermissionDictionary::MAIL_MAILBOX_LIST_ITEM_EDIT,
			self::ACTION_MAILBOX_CONNECT_TO_USER => PermissionDictionary::MAIL_MAILBOX_LIST_ITEM_EDIT,
			self::ACTION_MAILBOX_MASS_CONNECT_ENTER => PermissionDictionary::MAIL_MAILBOX_LIST_ITEM_EDIT,
			self::ACTION_MAILBOX_LIST_ITEM_VIEW => PermissionDictionary::MAIL_MAILBOX_LIST_ITEM_VIEW,
			self::ACTION_MAILBOX_SELF_CONNECT => PermissionDictionary::MAIL_MAILBOX_CONNECT,
			self::ACTION_MAILBOX_LIST_VIEW => PermissionDictionary::MAIL_MAILBOX_LIST_ITEM_VIEW,
			self::ACTION_MAILBOX_INTEGRATION_CRM_EDIT => PermissionDictionary::MAIL_MAILBOX_CRM_INTEGRATION_EDIT,
		];
	}
}
