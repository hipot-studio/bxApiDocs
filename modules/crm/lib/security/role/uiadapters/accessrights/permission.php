<?php

namespace Bitrix\Crm\Security\Role\UIAdapters\AccessRights;

use Bitrix\Crm\Security\Role\Manage\RoleManagerSelectionFactory;
use Bitrix\Crm\Security\Role\Manage\RoleSelectionManager;

final class Permission extends \Bitrix\UI\AccessRights\V2\Permission
{
	public function __construct(array $params = [], ?int $userId = null)
	{
		parent::__construct($params, $userId);

		$this->params['isAutomation'] = isset($params['isAutomation'])
			&& ($params['isAutomation'] === true || $params['isAutomation'] === 'true');
	}

	public function canUpdate(): bool
	{
		$manager = $this->getRoleManager();

		return $manager && $manager->hasPermissionsToEditRights();
	}

	private function getRoleManager(): ?RoleSelectionManager
	{
		$params = $this->params ?? [];
		$criterion = $params['criterion'] ?? null;
		$sectionCode = $params['sectionCode'] ?? null;
		$isAutomation = $params['isAutomation'] ?? false;

		return (new RoleManagerSelectionFactory())
			->setCustomSectionCode($sectionCode)
			->setAutomation($isAutomation)
			->create($criterion)
		;
	}
}