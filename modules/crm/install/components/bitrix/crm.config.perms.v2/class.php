<?php

use Bitrix\Crm\AutomatedSolution\AutomatedSolutionManager;
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
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\AccessRightsDTO;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Queries\QueryRoles;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;
use \Bitrix\UI\AccessRights\V2\Config;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!CModule::IncludeModule('crm'))
{
	ShowError(Loc::getMessage('CRM_MODULE_NOT_INSTALLED'));

	return;
}

class CrmConfigPermsV2 extends Base implements Controllerable
{
	private ?string $criterion;
	private ?string $sectionCode = null;
	private bool $isAutomation = false;
	private ?RoleSelectionManager $manager = null;
	private Config $config;

	public function getDataAction(array $controllerData): ?array
	{
		$this->criterion = $controllerData['criterion'] ?? null;
		$this->sectionCode = $controllerData['sectionCode'] ?? null;
		$this->isAutomation = $controllerData['isAutomation'] === 'true';
		$this->manager = (new RoleManagerSelectionFactory())
			->setCustomSectionCode($this->sectionCode)
			->setAutomation($this->isAutomation)
			->create($this->criterion)
		;

		if ($this->manager === null)
		{
			$this->addError(ErrorCode::getNotFoundError());

			return null;
		}

		if (!$this->manager->hasPermissionsToEditRights())
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$accessRightsDto = (new QueryRoles($this->manager))->execute();

		return [
			'accessRightsData' => $accessRightsDto,
			'maxVisibleUserGroups' => $this->getMaxVisibleUserGroups($accessRightsDto),
			'additionalSaveParams' => $this->getControllerData(),
			'userSortConfigName' => $this->getConfig()->getContext(),
			'userSortConfig' => $this->getConfig()->getUserGroupsSortConfig(),
		];
	}

	public function init(): void
	{
		parent::init();

		$this->criterion = $this->arParams['criterion'] ?? null;
		$this->sectionCode = $this->arParams['sectionCode'] ?? null;
		$this->isAutomation = $this->arParams['isAutomation'] ?? false;

		$this->manager = (new RoleManagerSelectionFactory())
			->setCustomSectionCode($this->sectionCode)
			->setAutomation($this->isAutomation)
			->create($this->criterion)
		;

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

		$accessRightsDto = (new QueryRoles($this->manager))->execute();
		$this->config = $this->getConfig();

		$this->arResult['accessRightsData'] = $accessRightsDto;
		$this->arResult['maxVisibleUserGroups'] = $this->getMaxVisibleUserGroups($accessRightsDto);
		$this->arResult['controllerData'] = $this->getControllerData();
		$this->arResult['isSharedCrmPermissionsSlider'] = $this->criterion === AllSelection::CRITERION;
		$this->arResult['analytics'] = $this->getAnalytics();
		$this->arResult['userSortConfigName'] = $this->config->getContext();
		$this->arResult['userSortConfig'] = $this->config->getUserGroupsSortConfig();

		$shouldDisplayLeftMenu = false;
		$this->arResult['menuId'] = $this->manager->getMenuId();
		if ($this->arResult['menuId'])
		{
			$shouldDisplayLeftMenu = true;
			$this->prepareLeftMenu();
		}
		$this->arResult['shouldDisplayLeftMenu'] = $shouldDisplayLeftMenu;

		$this->IncludeComponentTemplate();
	}

	public function configureActions(): array
	{
		return [];
	}

	/**
	 * Limit max visible roles based on total cells estimate. Since CRM perms can have A LOT of content, browser
	 * can die if it renders everything at once.
	 */
	private function getMaxVisibleUserGroups(AccessRightsDTO $rolesData): ?int
	{
		$limitFromOptions = Option::get('crm', 'perms_v2_config_max_roles');
		if (is_numeric($limitFromOptions) && (int)$limitFromOptions > 0)
		{
			return (int)$limitFromOptions;
		}

		$countAccessRights = 0;
		foreach ($rolesData->accessRights as $accessRight)
		{
			$countAccessRights += count($accessRight['rights']);
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

	private function getComponentName(): string
	{
		return str_replace('bitrix:', '', $this->getName());
	}

	private function getConfig(): Config
	{
		return Config::getInstanceByContext($this->getConfigContext());
	}

	private function getConfigContext(): string
	{
		$additionalSaveParams = $this->getControllerData();
		ksort($additionalSaveParams);

		return Json::encode([
			'component' => $this->getComponentName(),
			$additionalSaveParams,
		]);
	}
}
