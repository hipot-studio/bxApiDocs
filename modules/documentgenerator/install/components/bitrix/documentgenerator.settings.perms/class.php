<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)
{
	die();
}

use Bitrix\DocumentGenerator\Infrastructure\Agent\Access\DepartmentAccessCodesMigrateAgent;
use Bitrix\DocumentGenerator\Driver;
use Bitrix\DocumentGenerator\Integration\Bitrix24Manager;
use Bitrix\DocumentGenerator\Integration\UI\AccessRights\V2\AccessRightsProvider;
use Bitrix\DocumentGenerator\Repository\RoleRepository;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\UI\AccessRights\V2\AccessRightsBuilder;
use Bitrix\UI\AccessRights\V2\Options;
use Bitrix\UI\AccessRights\V2\Options\AdditionalMemberOptions;
use Bitrix\UI\Toolbar\Facade\Toolbar;
use Bitrix\Main\Error;
use Bitrix\DocumentGenerator\Integration\UI\InfoError;

class DocumentGeneratorSettingsPermsComponent extends CBitrixComponent
{
	private AccessRightsBuilder $accessRightsBuilder;

	private const ACCESS_RIGHTS_CONTAINER_ID = 'documentgenerator__access-rights-container';

	private function init(): Result
	{
		$result = $this->includeModules();
		if (!$result->isSuccess())
		{
			return $result;
		}

		if (!Driver::getInstance()->getUserPermissions()->canModifySettings())
		{
			$error = new Error(Loc::getMessage('DOCGEN_SETTINGS_PERMS_PERMISSIONS_ERROR'));

			return $result->addError($error);
		}

		$roleRepository = new RoleRepository();
		$accessRightsProvider = new AccessRightsProvider($roleRepository);
		$this->accessRightsBuilder =  new AccessRightsBuilder($accessRightsProvider);

		return $result;
	}

	private function includeModules(): Result
	{
		$result = new Result();

		if (!Loader::includeModule('documentgenerator'))
		{
			return $result->addError(new Error(Loc::getMessage('DOCGEN_SETTINGS_PERMS_MODULE_DOCGEN_ERROR')));
		}

		if (!Loader::includeModule('ui'))
		{
			return $result->addError(new Error(Loc::getMessage('DOCGEN_SETTINGS_PERMS_MODULE_UI_ERROR')));
		}

		return $result;
	}

	public function executeComponent(): void
	{
		$result = $this->init();
		if (!$result->isSuccess())
		{
			InfoError::fromResult($result)?->include();

			return;
		}

		Toolbar::deleteFavoriteStar();
		$this->configureAccessRightsOptions();

		$this->includeComponentTemplate();
	}

	private function configureAccessRightsOptions(): void
	{
		$options = new Options($this->getName(), self::ACCESS_RIGHTS_CONTAINER_ID);
		$options
			->setActionSave('save')
			->setBodyType('json')
			->setIsSaveOnlyChangedRights(false)
			->configureAdditionalMembersParams(static function (AdditionalMemberOptions $options): void {
				$options
					->setUseStructureDepartmentsProviderTab(DepartmentAccessCodesMigrateAgent::isDone());
			})
			->setAccessRights($this->accessRightsBuilder->buildAccessRights())
			->setUserGroups($this->accessRightsBuilder->buildUserGroups());

		$this->arResult['accessRightsOptions'] = $options;
	}
}
