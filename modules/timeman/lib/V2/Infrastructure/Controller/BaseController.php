<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Timeman\Security\UserPermissionsManager;
use Bitrix\Timeman\Service\DependencyManager;

class BaseController extends JsonController
{
	protected int $userId = 0;

	protected function init(): void
	{
		$this->userId = (int)CurrentUser::get()->getId();

		parent::init();
	}

	public function getDefaultPreFilters()
	{
		$prefilters = [
			...parent::getDefaultPreFilters(),
		];

		return $prefilters;
	}

	protected function getAccessManager(): UserPermissionsManager
	{
		return DependencyManager::getInstance()->getUserPermissionsManager($this->getUser());
	}

	private function getUser(): ?\CUser
	{
		global $USER;

		if ($USER instanceof \CUser)
		{
			return $USER;
		}

		return null;
	}
}
