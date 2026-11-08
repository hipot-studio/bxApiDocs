<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\User;

use Bitrix\Extranet\Service\ServiceContainer;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Socialnetwork\Site\Site;

class User
{
	protected int $id;

	public function __construct(int $id)
	{
		$this->id = $id;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function isCollaber(): bool
	{
		if (!Loader::includeModule('extranet'))
		{
			return false;
		}

		return ServiceContainer::getInstance()->getCollaberService()->isCollaberById($this->id);
	}

	public function isExtranet(): bool
	{
		if (ModuleManager::isModuleInstalled('intranet'))
		{
			return !$this->isIntranet();
		}

		// Without intranet the portal has no user types, so only extranet knows who is external there
		if (ModuleManager::isModuleInstalled('extranet'))
		{
			return \Bitrix\Socialnetwork\Integration\Extranet\User::isExtranet($this->id) ?? true;
		}

		return false;
	}

	public function isIntranet(): bool
	{
		return \Bitrix\Socialnetwork\Integration\Intranet\User::isIntranet($this->id);
	}

	public function getSiteId(): string
	{
		$site = Site::getInstance();

		if ($this->isIntranet())
		{
			return $site->getMainSiteId();
		}

		return $site->getExtranetSiteId();
	}
}