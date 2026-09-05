<?php

namespace Bitrix\Mail\Integration\Crm;

use Bitrix\Main\Loader;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;

class Permissions
{
	private static ?Permissions $instance = null;
	private bool $isCrmInstalled;

	public static function getInstance(): self
	{
		if (!isset(self::$instance))
		{
			self::$instance = new self;
		}

		return self::$instance;
	}

	public function hasAccessToCrm(?int $userId = null): bool
	{
		return $this->isCrmInstalled && Container::getInstance()->getUserPermissions($userId)->entityType()->canReadSomeItemsInCrm();
	}

	/**
	 * Whether the user may read the given CRM entity. Incorrect entity type /
	 * id pairs yield false, so the caller may pass client-supplied values.
	 */
	public function canReadEntity(int $entityTypeId, int $entityId, ?int $userId = null): bool
	{
		if (!$this->isCrmInstalled)
		{
			return false;
		}

		$identifier = ItemIdentifier::createByParams($entityTypeId, $entityId);
		if ($identifier === null)
		{
			return false;
		}

		return Container::getInstance()->getUserPermissions($userId)->item()->canReadItemIdentifier($identifier);
	}

	public function canEditExclusionItems(?int $userId = null): bool
	{
		return $this->isCrmInstalled && Container::getInstance()->getUserPermissions($userId)->exclusion()->canEditItems();
	}

	public function canDeleteActivity(?int $userId = null): bool
	{
		return $this->isCrmInstalled
			&& Container::getInstance()->getUserPermissions($userId)->entityType()->canDeleteItems(\CCrmOwnerType::Activity);
	}

	private function __construct()
	{
		$this->isCrmInstalled = Loader::includeModule('crm');
	}
}
