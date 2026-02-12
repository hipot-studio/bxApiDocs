<?php

use Bitrix\Crm\Component\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Integration\Catalog\Contractor\CategoryRepository;
use Bitrix\Crm\Security\Role\Manage\Manager\AllSelection;
use Bitrix\Crm\Integration\Analytics;
use Bitrix\Crm\Security\Role\Manage\Manager\ButtonSelection;
use Bitrix\Crm\Security\Role\Manage\Manager\Contract\SectionableRoleSelectionManager;
use Bitrix\Crm\Security\Role\Manage\Manager\CustomSectionSelection;
use Bitrix\Crm\Security\Role\Manage\Manager\ContractorSelection;
use Bitrix\Crm\Security\Role\Manage\Manager\WebFormSelection;
use Bitrix\Crm\Security\Role\Manage\RoleManagerSelectionFactory;
use Bitrix\Crm\Security\Role\Manage\RoleSelectionManager;
use Bitrix\Crm\Security\Role\Utils\RoleManagerUtils;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;
use Bitrix\UI\AccessRights\V2\Options;
use Bitrix\UI\AccessRights\V2\Config;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Crm\Security\Role\Utils\RolePermissionLogContext;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Commands\DeleteRoleCommand;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Commands\DTO\UserGroupsData;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Commands\UpdateRoleCommand;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Queries\QueryAccessRights;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\UserGroupsProvider;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Utils\PermCodeTransformer;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Validators\UserGroupDataValidator;
use Bitrix\Crm\Security\Role\Validators\DeleteRoleValidator;
use Bitrix\Main\ArgumentException;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!CModule::IncludeModule('crm'))
{
	ShowError(Loc::getMessage('CRM_MODULE_NOT_INSTALLED'));

	return;
}

if (!CModule::IncludeModule('ui'))
{
	ShowError('UI module is not installed');

	return;
}

class CrmConfigPermsV2 extends Base implements Controllerable
{
	private ?string $criterion;
	private ?string $sectionCode = null;
	private bool $isAutomation = false;
	private ?RoleSelectionManager $manager = null;
	private ?Config $config = null;

	private function initManager(array $params): void
	{
		$this->criterion = $params['criterion'] ?? null;
		$this->sectionCode = $params['sectionCode'] ?? null;
		$this->isAutomation = $params['isAutomation'] ?? false;

		$this->manager = (new RoleManagerSelectionFactory())
			->setCustomSectionCode($this->sectionCode)
			->setAutomation($this->isAutomation)
			->create($this->criterion)
		;
	}

	public function init(): void
	{
		parent::init();

		if ($this->manager === null)
		{
			$this->addError(ErrorCode::getNotFoundError());

			return;
		}

		if (!$this->manager->hasPermissionsToEditRights())
		{
			$this->addError(ErrorCode::getAccessDeniedError());
		}
	}

	public function executeComponent(): void
	{
		$this->initManager($this->arParams);
		$this->init();
		if ($this->getErrors())
		{
			$this->showFirstErrorViaInfoErrorUI();

			return;
		}

		if (!$this->manager->isAvailableTool())
		{
			$this->manager->printInaccessibilityContent();

			return;
		}

		$this->arResult['options'] = $this->prepareOptions();

		$this->arResult['isSharedCrmPermissionsSlider'] = $this->criterion === AllSelection::CRITERION;

		$shouldDisplayLeftMenu = false;
		$this->arResult['menuId'] = $this->manager->getMenuId();
		if ($this->arResult['menuId'])
		{
			$shouldDisplayLeftMenu = true;
			$this->prepareLeftMenu();
		}
		$this->arResult['shouldDisplayLeftMenu'] = $shouldDisplayLeftMenu;

		$this->includeComponentTemplate();
	}

	public function configureActions(): array
	{
		return [
			'save' => [
				'+prefilters' => [
					new ActionFilter\ContentType([ActionFilter\ContentType::JSON]),
				],
			],
			'getData' => [
				'+prefilters' => [
					new ActionFilter\ContentType([ActionFilter\ContentType::JSON]),
				],
			],
		];
	}

	public function saveAction(array $userGroups = [], array $deletedUserGroups = [], array $parameters = []): ?array
	{
		$this->initManager($parameters);
		$this->init();
		if ($this->getErrors())
		{
			return null;
		}

		$tariffResult = RoleManagerUtils::getInstance()->checkTariffRestriction();
		if (!$tariffResult->isSuccess())
		{
			$this->addErrors($tariffResult->getErrors());

			return null;
		}

		return $this->wrapInPermissionLogContext($parameters, function () use ($userGroups, $deletedUserGroups) {
			$deleteResult = $this->deleteUserGroups($deletedUserGroups);
			if (!$deleteResult->isSuccess())
			{
				$this->addErrors($deleteResult->getErrors());

				return null;
			}

			$userGroupDTOs = UserGroupsData::makeFromArray($userGroups, $this->manager->getGroupCode());

			$validationResult = UserGroupDataValidator::getInstance()->validate($userGroupDTOs);
			if (!$validationResult->isSuccess())
			{
				$this->addErrors($validationResult->getErrors());

				return null;
			}

			if (!$this->isSaveUserGroupsAllowed($userGroupDTOs))
			{
				$this->addError(ErrorCode::getAccessDeniedError());

				return null;
			}

			$preSaveCheckResult = $this->manager->preSaveChecks($userGroupDTOs);
			if (!$preSaveCheckResult->isSuccess())
			{
				$this->addErrors($preSaveCheckResult->getErrors());

				return null;
			}

			$updateResult = UpdateRoleCommand::getInstance()->execute($userGroupDTOs);
			if (!$updateResult->isSuccess())
			{
				$this->addErrors($updateResult->getErrors());

				return null;
			}

			$accessRights = (new QueryAccessRights($this->manager))->execute();

			return [
				'USER_GROUPS' => UserGroupsProvider::createByManager($this->manager, $accessRights)
					->loadAll()
				,
			];
		});
	}

	private function wrapInPermissionLogContext(array $parameters, callable $callback): mixed
	{
		RolePermissionLogContext::getInstance()->set([
			'component' => 'crm.config.perms.v2',
			'criterion' => (string)($parameters['criterion'] ?? null),
			'sectionCode' => (string)($parameters['sectionCode'] ?? null),
			'isAutomation' => (bool)($parameters['isAutomation'] ?? false),
		]);

		$result = $callback();

		RolePermissionLogContext::getInstance()->clear();

		return $result;
	}

	private function deleteUserGroups(array $userGroupsToDelete): Result
	{
		$result = new Result();

		$validator = DeleteRoleValidator::getInstance();
		$command = DeleteRoleCommand::getInstance();

		foreach ($userGroupsToDelete as $roleId)
		{
			$validationResult = $validator->validate($roleId);
			if (!$validationResult->isSuccess())
			{
				$result->addErrors($validationResult->getErrors());
				continue;
			}

			$deleteResult = $command->execute($roleId);
			if (!$deleteResult->isSuccess())
			{
				$result->addErrors($deleteResult->getErrors());
			}
		}

		return $result;
	}

	/**
	 * @param UserGroupsData[] $userGroups
	 * @return bool
	 */
	private function isSaveUserGroupsAllowed(array $userGroups): bool
	{
		$checkedEntities = [];
		$transformer = PermCodeTransformer::getInstance();

		foreach ($userGroups as $userGroup)
		{
			foreach ($userGroup->accessRights as $accessRight)
			{
				try
				{
					$permission = $transformer->decodeAccessRightCode($accessRight->id);
				}
				catch (ArgumentException)
				{
					return false;
				}

				$isChecked = in_array($permission->entityCode, $checkedEntities, true);
				if ($isChecked)
				{
					continue;
				}

				if (!$this->userPermissions->permission()->canEdit($permission))
				{
					return false;
				}

				$checkedEntities[] = $permission->entityCode;
			}
		}

		return true;
	}

	public function getDataAction(array $controllerData): ?Options
	{
		$this->initManager($controllerData);
		$this->init();
		if ($this->getErrors())
		{
			return null;
		}

		return $this->prepareOptions();
	}

	private function prepareOptions(): Options
	{
		$accessRights = (new QueryAccessRights($this->manager))->execute();
		$userGroups = UserGroupsProvider::createByManager($this->manager, $accessRights)->loadAll();

		$options = new Options(
			$this->getName(),
			'bx-crm-perms-config-permissions'
		);

		$options
			->setModuleId('crm')
			->setActionSave('save')
			->setMode('class')
			->setBodyType('json')
			->setSearchContainerSelector('#crm-config-perms-v2-search-container')
			->setAccessRights($accessRights)
			->setUserGroups($userGroups)
			->setAdditionalSaveParams($this->getControllerData())
			->setAnalytics($this->getAnalytics())
			->setIsSaveOnlyChangedRights(true)
			->setMaxVisibleUserGroups($this->getMaxVisibleUserGroups($accessRights))
			->setUserSortConfigName($this->getConfig()->getContext())
			->setSortConfigForAllUserGroups($this->getConfig()->getUserGroupsSortConfig())
		;

		return $options;
	}

	/**
	 * Limit max visible roles based on total cells estimate. Since CRM perms can have A LOT of content, browser
	 * can die if it renders everything at once.
	 *
	 * @param \Bitrix\UI\AccessRights\V2\Options\RightSection[] $accessRights
	 *
	 * @return int|null
	 */
	private function getMaxVisibleUserGroups(array $accessRights): ?int
	{
		$limitFromOptions = Option::get('crm', 'perms_v2_config_max_roles');
		if (is_numeric($limitFromOptions) && (int)$limitFromOptions > 0)
		{
			return (int)$limitFromOptions;
		}

		$countAccessRights = 0;
		foreach ($accessRights as $accessRight)
		{
			$countAccessRights += count($accessRight->getRightItems());
		}

		if ($countAccessRights === 0)
		{
			return null;
		}

		$limit = (int)(50000 / $countAccessRights);

		if ($limit < 1)
		{
			$limit = 1;
		}
		elseif ($limit < 10)
		{
			$limit = round($limit);
		}
		elseif ($limit < 100)
		{
			$limit = round($limit / 5) * 5;
		}
		elseif ($limit < 1000)
		{
			$limit = round($limit / 50) * 50;
		}
		else
		{
			$limit = 1000;
		}

		return (int)$limit;
	}

	private function getControllerData(): array
	{
		return [
			'criterion' => $this->criterion,
			'sectionCode' => $this->sectionCode,
			'isAutomation' => $this->isAutomation,
		];
	}

	private function getAnalytics(): ?array
	{
		$builder = Analytics\Builder\Security\ViewEvent::createFromRequest($this->request);
		if (!$builder->validate()->isSuccess())
		{
			return null;
		}

		$data = $builder->buildData();
		unset($data['event']);

		return $data;
	}

	private function prepareLeftMenu(): void
	{
		$menuItems = [];

		/** @var SectionableRoleSelectionManager[] $sections */
		$sections = [
			new AllSelection(),
			new WebFormSelection(),
			new ButtonSelection(),
		];

		if (CategoryRepository::isAtLeastOneContractorExists())
		{
			$sections[] = new ContractorSelection();
		}

		$automatedSolutionManager = Container::getInstance()->getAutomatedSolutionManager();
		foreach ($automatedSolutionManager->getExistingIntranetCustomSections() as $customSection)
		{
			$customSectionSelection = new CustomSectionSelection($customSection);
			$sections[] = $customSectionSelection;
		}

		foreach ($sections as $section)
		{
			$this->appendMenuForSection($section, $menuItems);
		}

		$this->appendCustomSectionListMenu($menuItems);

		$this->arResult['leftMenu'] = $menuItems;
	}

	private function appendMenuForSection(SectionableRoleSelectionManager $section, array &$menuItems): void
	{
		if (!$section->hasPermissionsToEditRights())
		{
			return;
		}

		$controllerData = $section->getControllerData();
		$encodedControllerData = htmlspecialcharsbx(Json::encode($controllerData));
		$sectionMenu = [
			'NAME' => $section->getTitle(),
			'ATTRIBUTES' => [
				'onclick' => "ConfigPerms.openPermission({$encodedControllerData});",
				'data-menu-id' => $controllerData['menuId'],
				'title' => htmlspecialcharsbx($section->getTitle()),
			],
			'CAN_BE_ACTIVE' => true,
			'ACTIVE' => $this->manager->getMenuId() === $controllerData['menuId'],
			'SUBMENU_OPEN' => $this->manager->getMenuId() === $controllerData['menuId'],
			'CHILDREN' => [],
		];

		foreach ($section->buildModels() as $model)
		{
			$sectionMenu['CHILDREN'][] = [
				'NAME' => $model->name() . ' ' . $model->description(),
				'ATTRIBUTES' => [
					'onclick' => "ConfigPerms.AccessRights.scrollToSection('{$model->code()}');",
					'title' => htmlspecialcharsbx($model->name() . ' ' . $model->description()),
				],
				'CAN_BE_ACTIVE' => false,
			];
		}

		$menuItems[] = $sectionMenu;
	}

	private function appendCustomSectionListMenu(array &$menuItems): void
	{
		$customSectionListSelection = new \Bitrix\Crm\Security\Role\Manage\Manager\CustomSectionListSelection();
		if (!$customSectionListSelection->hasPermissionsToEditRights())
		{
			return;
		}

		$model = $customSectionListSelection->buildModels()[0];
		$controllerData = $customSectionListSelection->getControllerData();
		$encodedControllerData = htmlspecialcharsbx(Json::encode($controllerData));
		$buttonMenu = [
			'NAME' => $model->name(),
			'ATTRIBUTES' => [
				'onclick' => "ConfigPerms.openPermission({$encodedControllerData});",
				'data-menu-id' => $controllerData['menuId']
			],
			'CAN_BE_ACTIVE' => true,
			'ACTIVE' => $this->manager->getMenuId() === $controllerData['menuId'],
		];

		$menuItems[] = $buttonMenu;
	}

	private function getConfig(): Config
	{
		$this->config ??= Config::getInstanceByContext($this->getConfigContext());

		return $this->config;
	}

	private function getConfigContext(): string
	{
		$additionalSaveParams = $this->getControllerData();
		ksort($additionalSaveParams);

		return Json::encode([
			'component' => $this->getName(),
			$additionalSaveParams,
		]);
	}
}
