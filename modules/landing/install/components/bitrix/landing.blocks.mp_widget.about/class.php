<?php

use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\HumanResources\Service;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\StructureRole;
use Bitrix\HumanResources\Util\StructureHelper;
use Bitrix\Landing\Connector\Crm;
use Bitrix\Landing\Mainpage;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Extension;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

CBitrixComponent::includeComponentClass('bitrix:landing.blocks.mp_widget.base');

class LandingBlocksMainpageWidgetAbout extends LandingBlocksMainpageWidgetBase
{
	private const WIDGET_CSS_VAR_PROPERTIES = [
		'COLOR_TEXT_V2' => '--widget-color-v2',
		'COLOR_ICON' => '--widget-color-icon',
		'COLOR_BORDER' => '--widget-color-border',
		'COLOR_BORDER_V2' => '--widget-color-border-v2',
	];

	/**
	 * Base executable method.
	 *
	 * @return void
	 */
	public function executeComponent(): void
	{
		$this->initializeParams();
		$this->getData();

		parent::executeComponent();
	}

	/**
	 * Returns the boss ID using static context.
	 *
	 * @return int Boss user ID or 0 if not found.
	 */
	public static function getBossId(): int
	{
		if (!Loader::includeModule('humanresources'))
		{
			return 0;
		}

		$rootDepartment = StructureHelper::getRootStructureDepartment();
		if (!$rootDepartment)
		{
			return 0;
		}

		$heads = Service\Container::getNodeMemberService()->getDefaultHeadRoleEmployees($rootDepartment->id);
		$headFirst = $heads->getFirst();

		return $headFirst->entityId ?? 0;
	}

	protected function initializeParams(): void
	{
		$this->checkParam('BOSS_ID', self::getBossId());
		$this->checkParam('COLOR_HEADERS', '#ffffff');
		$this->checkParam('COLOR_TEXT', '#ffffff');
		$this->checkParam('COLOR_TEXT_V2', 'hsla(179, 73%, 84%, 0.54)');
		$this->checkParam('COLOR_ICON', 'var(--primary)');
		$this->checkParam('COLOR_BORDER', 'hsl(210, 3%, 76%, 0.7)');
		$this->checkParam('COLOR_BORDER_V2', 'var(--primary)');

		foreach (self::WIDGET_CSS_VAR_PROPERTIES as $property => $cssVar)
		{
			$this->addCssVarProperty($property, $cssVar);
		}
	}

	protected function getData(): void
	{
		if (Mainpage\Manager::isUseDemoData())
		{
			$data = $this->getDemoData();
			Extension::load(['ui.icon-set.main']);
			Extension::load(['ui.icon-set.crm']);
		}
		else
		{
			$data = $this->getRealData();
		}

		if ($data['TITLE'] === '#COMPANY_NAME#')
		{
			$data['TITLE'] = $this->getCompanyName();
		}

		$this->arResult['TITLE'] = $data['TITLE'];
		$this->arResult['TEXT'] = $data['TEXT'];
		$this->arResult['CARDS'] = $data['CARDS'];
		$this->arResult['BOSS'] = $data['BOSS'];
	}

	protected function getDemoData(): array
	{
		$count = [
			'EMPLOYEES' => 1231,
			'SUPERVISORS' => 210,
			'DEPARTMENTS' => 480,
		];

		return [
			'TITLE' => Loc::getMessage('LANDING_WIDGET_CLASS_ABOUT_TITLE'),
			'TEXT' => Loc::getMessage('LANDING_WIDGET_CLASS_ABOUT_TEXT'),
			'CARDS' => [
				[
					'icon' => 'ui-icon-set --persons-3',
					'title' => $count['EMPLOYEES'],
					'text' => Loc::getMessagePlural(
						'LANDING_WIDGET_CLASS_ABOUT_TEXT_EMPLOYEES_BASE',
						$count['EMPLOYEES']
					),
				],
				[
					'icon' => 'ui-icon-set --customer-card',
					'title' => $count['SUPERVISORS'],
					'text' => Loc::getMessagePlural(
						'LANDING_WIDGET_CLASS_ABOUT_TEXT_SUPERVISORS_BASE',
						$count['SUPERVISORS']
					),
				],
				[
					'icon' => 'ui-icon-set --person-flag',
					'title' => $count['DEPARTMENTS'],
					'text' => Loc::getMessagePlural(
						'LANDING_WIDGET_CLASS_ABOUT_TEXT_DEPARTMENTS_BASE',
						$count['DEPARTMENTS']
					),
				],
			],
			'BOSS' => [
				'NAME' => Loc::getMessage('LANDING_WIDGET_CLASS_ABOUT_BOSS_NAME'),
				'WORK_POSITION' => Loc::getMessage('LANDING_WIDGET_CLASS_ABOUT_BOSS_WORK_POSITION'),
				'PERSONAL_PHOTO_SRC' => 'https://cdn.bitrix24.site/bitrix/images/landing/widget/about/avatar.png',
			],
		];
	}

	protected function getRealData(): array
	{
		$realData = [];

		$this->checkParam('TITLE', Loc::getMessage('LANDING_WIDGET_CLASS_ABOUT_TITLE'));
		$this->checkParam('TEXT', Loc::getMessage('LANDING_WIDGET_CLASS_ABOUT_TEXT'));

		$this->checkParam('SHOW_EMPLOYEES', 'Y');
		$this->checkParam('SHOW_SUPERVISORS', 'Y');
		$this->checkParam('SHOW_DEPARTMENTS', 'Y');

		$realData['TITLE'] = $this->arParams['TITLE'];
		$realData['TEXT'] = $this->arParams['TEXT'];

		if (!isset($this->arParams['BOSS_ID']))
		{
			$this->arParams['BOSS_ID'] = self::getBossId();
		}

		$realData['BOSS'] = $this->getBoss();
		$realData['CARDS'] = [];

		if ($this->arParams['SHOW_EMPLOYEES'] === 'Y')
		{
			$employeesCount = $this->getEmployeesCount();
			$realData['CARDS'][] = [
				'icon' => 'ui-icon-set --persons-3',
				'title' => $employeesCount,
				'text' => Loc::getMessagePlural('LANDING_WIDGET_CLASS_ABOUT_TEXT_EMPLOYEES_BASE', $employeesCount),
			];

			Extension::load(['ui.icon-set.main']);
		}

		if ($this->arParams['SHOW_SUPERVISORS'] === 'Y')
		{
			$supervisorsCount = $this->getSupervisorsCount();
			$realData['CARDS'][] = [
				'icon' => 'ui-icon-set --customer-card',
				'title' => $supervisorsCount,
				'text' => Loc::getMessagePlural(
					'LANDING_WIDGET_CLASS_ABOUT_TEXT_SUPERVISORS_BASE',
					$supervisorsCount
				),
			];

			Extension::load(['ui.icon-set.crm']);
		}

		if ($this->arParams['SHOW_DEPARTMENTS'] === 'Y')
		{
			$departmentsCount = $this->getDepartmentsCount();
			$realData['CARDS'][] = [
				'icon' => 'ui-icon-set --person-flag',
				'title' => $departmentsCount,
				'text' => Loc::getMessagePlural(
					'LANDING_WIDGET_CLASS_ABOUT_TEXT_DEPARTMENTS_BASE',
					$departmentsCount
				),
			];

			Extension::load(['ui.icon-set.main']);
		}

		return $realData;
	}

	/**
	 * Returns information about the company boss.
	 *
	 * @return array Associative array with boss information (name, last name, photo, position, etc.)
	 */
	protected function getBoss(): array
	{
		if (!$this->arParams['BOSS_ID'] || $this->arParams['BOSS_ID'] <= 0)
		{
			return [];
		}

		$fields = [
			'NAME',
			'LAST_NAME',
			'PERSONAL_PHOTO',
			'WORK_POSITION',
		];

		$res = CUser::getList(
			'ID',
			'ASC',
			[
				'ID' => (int)$this->arParams['BOSS_ID'],
				'ACTIVE' => 'Y',
			],
			[
				'SELECT' => $fields,
				'FIELDS' => $fields,
			]
		);

		$boss = $res->Fetch();
		if (!$boss)
		{
			return [];
		}
		$resizeFile = CFile::ResizeImageGet(
			$boss['PERSONAL_PHOTO'],
			["width" => 58, "height" => 58],
			BX_RESIZE_IMAGE_EXACT
		);
		$boss['PERSONAL_PHOTO_SRC'] = $resizeFile['src'];
		$resizeFileSmall = CFile::ResizeImageGet(
			$boss['PERSONAL_PHOTO'],
			["width" => 47, "height" => 47],
			BX_RESIZE_IMAGE_EXACT
		);
		$boss['PERSONAL_PHOTO_SRC_SMALL'] = $resizeFileSmall['src'];
		$boss['ID'] = (int)$this->arParams['BOSS_ID'];

		$name = $boss['NAME'] ?? '';
		$lastName = $boss['LAST_NAME'] ?? '';
		$boss['FULL_NAME'] = $name . ' ' . $lastName;

		if ($boss['ID'] > 0)
		{
			$boss['LINK'] = "/company/personal/user/{$boss['ID']}/";
		}

		return $boss;
	}

	/**
	 * Returns the company name.
	 *
	 * @return string The name of the company.
	 */
	private function getCompanyName(): string
	{
		$defaultCompanyName = Loc::getMessage('LANDING_WIDGET_CLASS_ABOUT_TITLE');

		if (!Loader::includeModule('crm'))
		{
			return $defaultCompanyName;
		}

		$crmContacts = Crm::getContacts(0);

		return isset($crmContacts['COMPANY']) ? (string)$crmContacts['COMPANY'] : $defaultCompanyName;
	}

	/**
	 * Returns the total number of employees in the company structure.
	 *
	 * @return int Number of employees.
	 */
	private function getEmployeesCount(): int
	{
		if (!Loader::includeModule('humanresources'))
		{
			return 0;
		}

		return Container::getUserDepartmentService()->getTotalEmployeeCount();
	}

	/**
	 * Returns the total number of supervisors in the company structure.
	 *
	 * @return int Number of supervisors.
	 */
	private function getSupervisorsCount(): int
	{
		if (!Loader::includeModule('humanresources'))
		{
			return 0;
		}

		$nodeMembers = (new NodeMemberDataBuilder())
			->setFilter(new NodeMemberFilter(
							entityType: MemberEntityType::USER,
						))
			->addStructureRole(StructureRole::HEAD)
			->getAll()
		;

		$headIds = [];
		foreach($nodeMembers as $member)
		{
			$headIds[] = $member->entityId;
		}

		return count(array_unique($headIds));
	}

	/**
	 * Returns the total number of departments in the company structure.
	 *
	 * @return int Number of departments.
	 */
	private function getDepartmentsCount(): int
	{
		if (!Loader::includeModule('humanresources'))
		{
			return 0;
		}

		$nodes =
			(new NodeDataBuilder())
				->addFilter(
					new NodeFilter(
						entityTypeFilter: NodeTypeFilter::createForDepartment(),
						depthLevel: DepthLevel::FULL,
					),
				)
				->getAll()
		;

		return count($nodes);
	}
}
