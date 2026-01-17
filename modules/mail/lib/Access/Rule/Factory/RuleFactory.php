<?php

namespace Bitrix\Mail\Access\Rule\Factory;

use Bitrix\Mail\Access\MailActionDictionary;
use Bitrix\Mail\Access\Permission\PermissionDictionary;
use Bitrix\Main\Access\Permission\PermissionDictionary as PermissionDictionaryAlias;
use Bitrix\Main\Access\Rule\Factory\RuleControllerFactory;
use Bitrix\Main\Access\AccessibleController;

class RuleFactory extends RuleControllerFactory
{
	protected const MAIL_BASE_RULE = 'MailBase';
	protected const MAILBOX_BASE_RULE = 'MailboxBase';

	protected function getClassName(string $action, AccessibleController $controller): ?string
	{
		$actionName = MailActionDictionary::getActionName($action);
		if (!$actionName)
		{
			return null;
		}

		$actionParts = explode('_', $actionName);
		$actionParts = array_map(static fn($el) => ucfirst(mb_strtolower($el)), $actionParts);

		$ruleClass = $this->getNamespace($controller) . implode($actionParts) . static::SUFFIX;

		if (class_exists($ruleClass))
		{
			return $ruleClass;
		}

		$actionPermissionMap = MailActionDictionary::getActionPermissionMap();
		if (array_key_exists($action, $actionPermissionMap))
		{
			$permissionId = (string)$actionPermissionMap[$action];

			if (PermissionDictionary::getType($permissionId) === PermissionDictionaryAlias::TYPE_TOGGLER)
			{
				return $this->getNamespace($controller) . static::MAIL_BASE_RULE . static::SUFFIX;
			}

			if (PermissionDictionary::isMailboxVariablesPermission($permissionId))
			{
				return $this->getNamespace($controller) . static::MAILBOX_BASE_RULE . static::SUFFIX;
			}

			return $this->getNamespace($controller) . static::MAIL_BASE_RULE . static::SUFFIX;
		}

		return null;
	}
}
