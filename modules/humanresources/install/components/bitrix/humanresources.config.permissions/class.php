<?php

use Bitrix\HumanResources\Enum\Access\RoleCategory;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\HumanResources\Access\StructureAccessController;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Internals\HumanResourcesBaseComponent;
use Bitrix\HumanResources\Service\Container;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!Bitrix\Main\Loader::includeModule('humanresources'))
{
	return;
}

class HumanResourcesConfigPermissionsComponent extends HumanResourcesBaseComponent
{
	public function exec(): void
	{
		$categoryFromRequest = $this->request->get('category');
		$category = is_string($categoryFromRequest)
			? (RoleCategory::tryFrom($categoryFromRequest) ?? RoleCategory::Department)
			: RoleCategory::Department;

		$can = StructureAccessController::can(
			CurrentUser::get()->getId(),
			$category === RoleCategory::Department
				? StructureActionDictionary::ACTION_USERS_ACCESS_EDIT
				: StructureActionDictionary::ACTION_TEAM_ACCESS_EDIT,
		);

		if (!$can)
		{
			$this->setTemplatePage('access_denied');

			return;
		}

		$this->setResults();
	}

	private function setResults(): void
	{
		$this->setTemplateTitle(Loc::getMessage('HUMAN_RESOURCES_CONFIG_PERMISSIONS_TITLE'));

		$categoryFromRequest = $this->request->get('category');
		$category = is_string($categoryFromRequest)
			? RoleCategory::tryFrom($categoryFromRequest) ?? RoleCategory::Department
			: RoleCategory::Department;

		$rolePermission = Container::getAccessRolePermissionService();
		$rolePermission->setCategory($category);

		$this->arResult['ACCESS_RIGHTS'] = $rolePermission->getAccessRights();
		$this->arResult['USER_GROUPS'] = $rolePermission->getUserGroups();
		$this->arResult['CATEGORY'] = $category->value;

		if (!\Bitrix\HumanResources\Config\Storage::canUsePermissionConfig())
		{
			$this->arResult['CANT_USE'] = true;
		}

		$this->arResult['ANALYTIC_CONTEXT'] = [
			'tool' => 'structure',
			'category' => 'roles',
		];
	}
}