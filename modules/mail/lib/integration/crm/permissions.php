<?php

namespace Bitrix\Mail\Integration\Crm;

use Bitrix\Main\Loader;
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
