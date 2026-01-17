<?php

namespace Bitrix\Intranet\Integration\UI\EntitySelector;

use Bitrix\Iblock\EO_Section_Collection;
use Bitrix\Iblock\SectionTable;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Integration\UI\EntitySelector\UserProvider;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;

class DepartmentProvider extends BaseProvider
{
	public const MODE_USERS_AND_DEPARTMENTS = 'usersAndDepartments';
	public const MODE_USERS_ONLY = 'usersOnly';
	public const MODE_DEPARTMENTS_ONLY = 'departmentsOnly';

	private $limit = 100;

	public function __construct(array $options = [])
	{
		parent::__construct();

		if (isset($options['selectMode']) && in_array($options['selectMode'], self::getSelectModes()))
		{
			$this->options['selectMode'] = $options['selectMode'];
		}
		else
		{
			$this->options['selectMode'] = self::MODE_USERS_ONLY;
		}

		$this->options['allowFlatDepartments'] = (
			isset($options['allowFlatDepartments']) && $options['allowFlatDepartments'] === true
		);

		$this->options['allowOnlyUserDepartments'] = (
			isset($options['allowOnlyUserDepartments']) && $options['allowOnlyUserDepartments'] === true
		);

		$this->options['allowSelectRootDepartment'] = $this->getSelectMode() === self::MODE_DEPARTMENTS_ONLY;
		if (isset($options['allowSelectRootDepartment']) && is_bool($options['allowSelectRootDepartment']))
		{
			$this->options['allowSelectRootDepartment'] = $options['allowSelectRootDepartment'];
		}

		if (isset($options['userOptions']) && is_array($options['userOptions']))
		{
			if (Loader::includeModule('socialnetwork'))
			{
				$userProvider = new UserProvider($options['userOptions']); // process options by UserProvider
				$this->options['userOptions'] = $userProvider->getOptions();
			}
		}

		$this->options['hideChatBotDepartment'] = true;
		if (isset($options['hideChatBotDepartment']) && is_bool($options['hideChatBotDepartment']))
		{
			$this->options['hideChatBotDepartment'] = $options['hideChatBotDepartment'];
		}

		$this->options['fillDepartmentsTab'] = true;
		if (isset($options['fillDepartmentsTab']) && is_bool($options['fillDepartmentsTab']))
		{
			$this->options['fillDepartmentsTab'] = $options['fillDepartmentsTab'];
		}

		$this->options['fillRecentTab'] = false;
		if (isset($options['fillRecentTab']) && is_bool($options['fillRecentTab']))
		{
			$this->options['fillRecentTab'] =
				$options['fillRecentTab'] && $this->options['selectMode'] === self::MODE_DEPARTMENTS_ONLY;
		}

		$this->options['depthLevel'] = 1;
		if (isset($options['depthLevel']) && is_int($options['depthLevel']) && $this->options['fillRecentTab'])
		{
			$this->options['depthLevel'] = $options['depthLevel'];
		}

		$this->options['shouldCountSubdepartments'] = false;
		if (isset($options['shouldCountSubdepartments']) && is_bool($options['shouldCountSubdepartments']))
		{
			$this->options['shouldCountSubdepartments'] = $options['shouldCountSubdepartments']
				&& (
					$this->options['selectMode'] === self::MODE_DEPARTMENTS_ONLY
					|| $this->options['selectMode'] === self::MODE_USERS_AND_DEPARTMENTS
				);
		}

		$this->options['shouldCountUsers'] = false;
		if (isset($options['shouldCountUsers']) && is_bool($options['shouldCountUsers']))
		{
			$this->options['shouldCountUsers'] = $options['shouldCountUsers']
				&& (
					$this->options['selectMode'] === self::MODE_USERS_ONLY
					|| $this->options['selectMode'] === self::MODE_USERS_AND_DEPARTMENTS
				);
		}
	}

	public function getSelectMode()
	{
		return $this->options['selectMode'];
	}

	public static function getSelectModes()
	{
		return [
			self::MODE_DEPARTMENTS_ONLY,
			self::MODE_USERS_ONLY,
			self::MODE_USERS_AND_DEPARTMENTS,
		];
	}

	public function getLimit(): int
	{
		return $this->limit;
	}

	protected function getUserOptions(Dialog $dialog): array
	{
		if (isset($this->getOptions()['userOptions']) && is_array($this->getOptions()['userOptions']))
		{
			return $this->getOptions()['userOptions'];
		}
		elseif ($dialog->getEntity('user') && is_array($dialog->getEntity('user')->getOptions()))
		{
			return $dialog->getEntity('user')->getOptions();
		}

		return [];
	}

	public function isAvailable(): bool
	{
		if (!$GLOBALS['USER']->isAuthorized())
		{
			return false;
		}

		if (!Loader::includeModule('socialnetwork') || !Loader::includeModule('iblock'))
		{
			return false;
		}

		return UserProvider::isIntranetUser();
	}

	public function getItems(array $ids): array
	{
		return $this->getDepartments($ids);
	}

	public function getSelectedItems(array $ids): array
	{
		return $this->getDepartments($ids, ['active' => null]);
	}

	public function fillDialog(Dialog $dialog): void
	{
		if ($this->options['fillDepartmentsTab'] === true || $this->options['fillRecentTab'] === true)
		{
			$limit = $this->getLimit();

			// Try to select all departments
			$departments = self::getStructure(['limit' => $limit]);
			$limitExceeded = $limit <= $departments->count();
			if ($limitExceeded)
			{
				// Select only the first level
				$departments = self::getStructure(['depthLevel' => $this->options['depthLevel']]);
			}

			if (!$limitExceeded || $this->getSelectMode() === self::MODE_USERS_ONLY)
			{
				// Turn off the user search
				$entity = $dialog->getEntity('department');
				if ($entity)
				{
					$entity->setDynamicSearch(false);
				}
			}

			$forceDynamic = $this->getSelectMode() === self::MODE_DEPARTMENTS_ONLY && !$limitExceeded ? false : null;

			if ($this->options['fillRecentTab'] === true)
			{
				$this->fillRecentDepartments($dialog, $departments);
			}

			if ($this->options['fillDepartmentsTab'] === true)
			{
				$this->fillDepartments($dialog, $departments, $forceDynamic);
			}
		}

		if ($this->options['fillDepartmentsTab'] === true)
		{
			$dialog->addTab(new Tab([
				'id' => 'departments',
				'title' => Loc::getMessage('INTRANET_ENTITY_SELECTOR_DEPARTMENTS_TAB_TITLE'),
				'itemMaxDepth' => 7,
				'icon' => [
					'default' => 'o-group',
					'selected' => 's-group',
				],
			]));
		}

	}

	private function getAllowOnlyUserDepartment(): ?array
	{
		$result = null;
		if ($this->options['allowOnlyUserDepartments'] === true)
		{
			$result = [];
			global $USER;
			if ($USER->isAuthorized())
			{
				$res = \CUser::getById($USER->getId());
				if (($user = $res->fetch()) && !empty($user['UF_DEPARTMENT']))
				{
					$result = $user['UF_DEPARTMENT'];
				}
			}
		}

		return $result;
	}

	private function fillRecentDepartments(Dialog $dialog, EO_Section_Collection $departments)
	{
		foreach ($departments as $department)
		{
			$isRootDepartment = $department->getDepthLevel() === 1 || $department->getId() === self::getRootDepartmentId();
			$hideRootDepartment = $isRootDepartment && !$this->options['allowSelectRootDepartment'];

			if ($hideRootDepartment && $isRootDepartment)
			{
				continue;
			}

			$item = new Item([
				'id' => $department->getId(),
				'entityId' => 'department',
				'title' => $department->getName(),
				'tabs' => 'recent',
			]);

			$dialog->addRecentItem($item);
		}
	}

	private function fillDepartments(Dialog $dialog, EO_Section_Collection $departments, ?bool $forceDynamic = null)
	{
		$allowDepartment = $this->getAllowOnlyUserDepartment();
		$parents = [];
		$parentIdList = [];
		foreach ($departments as $department)
		{
			$isRootDepartment =
				$department->getDepthLevel() === 1 || $department->getId() === self::getRootDepartmentId()
			;
			$hideRootDepartment = $isRootDepartment && !$this->options['allowSelectRootDepartment'];
			$parentIdList[$department->getId()] = $department->getIblockSectionId();

			$availableInRecentTab = true;
			if ($this->getSelectMode() === self::MODE_USERS_ONLY || $hideRootDepartment)
			{
				$availableInRecentTab = false;
			}

			if (
				is_array($allowDepartment)
				&& !$this->isAllowDepartment(
					$department->getId(),
					$department->getIblockSectionId(),
					$allowDepartment,
					$parentIdList
				)
			)
			{
				continue;
			}

			$subdepartmentsCount = null;
			if ($this->options['shouldCountSubdepartments'])
			{
				$subdepartmentsCount = $this->getSubdepartmentsCount($department->getId());
			}

			$usersCount = null;
			if ($this->options['shouldCountUsers'])
			{
				$usersOptions = $this->getUserOptions($dialog);
				$usersCount = UserProvider::getUsers(['departmentId' => $department->getId()] + $usersOptions)->count();
			}

			$item = new Item([
				'id' => $department->getId(),
				'entityId' => 'department',
				'title' => $department->getName(),
				'tabs' => 'departments',
				'searchable' => $availableInRecentTab,
				'availableInRecentTab' => $availableInRecentTab,
				'customData' => [
					'subdepartmentsCount' => $subdepartmentsCount,
					'usersCount' => $usersCount,
				],
				'nodeOptions' => [
					'dynamic' => is_bool($forceDynamic) ? $forceDynamic : true,
					'open' => $isRootDepartment,
				],
			]);

			if ($this->getSelectMode() === self::MODE_DEPARTMENTS_ONLY && !$hideRootDepartment)
			{
				$item->addChild(new Item([
					'id' => $department->getId(),
					'title' => $department->getName(),
					'entityId' => 'department',
					'nodeOptions' => [
						'title' => Loc::getMessage('INTRANET_ENTITY_SELECTOR_SELECT_DEPARTMENT'),
						//'avatar' => '/bitrix/js/intranet/entity-selector/src/images/department-option.svg',
						'renderMode' => 'override',
					],
				]));
			}
			elseif ($this->getSelectMode() === self::MODE_USERS_AND_DEPARTMENTS)
			{
				if (!$hideRootDepartment)
				{
					$item->addChild(new Item([
						'id' => $department->getId(),
						'title' => $department->getName(),
						'entityId' => 'department',
						'nodeOptions' => [
							'title' => Loc::getMessage('INTRANET_ENTITY_SELECTOR_ALL_EMPLOYEES_SUBDIVISIONS_MSGVER_2'),
							'avatar' => '/bitrix/js/intranet/entity-selector/src/images/department-option.svg',
							'renderMode' => 'override',
						],
					]));
				}

				if ($this->options['allowFlatDepartments'])
				{
					$item->addChild(new Item([
						'id' => $department->getId() . ':F',
						'entityId' => 'department',
						'title' => Loc::getMessage('INTRANET_ENTITY_SELECTOR_ONLY_EMPLOYEES_MSGVER_1', [
							'#DEPARTMENT_NAME#' => $department->getName(),
						]),
						'nodeOptions' => [
							'title' => Loc::getMessage('INTRANET_ENTITY_SELECTOR_ONLY_DEPARTMENT_EMPLOYEES_MSGVER_1'),
							'avatar' => '/bitrix/js/intranet/entity-selector/src/images/department-option.svg',
							'renderMode' => 'override',
						],
					]));
				}
			}

			$parentItem = $parents[$department->getIblockSectionId()] ?? null;
			if ($parentItem)
			{
				$parentItem->addChild($item);
			}
			else
			{
				$dialog->addItem($item);
			}

			$parents[$department->getId()] = $item;
		}
	}

	private function isAllowDepartment($departmentId, $parentId, $allowDepartment, $parentList): bool
	{
		$result = false;
		if (
			in_array($departmentId, $allowDepartment, true)
			|| in_array($parentId, $allowDepartment, true)
		)
		{
			$result = true;
		}
		elseif ($parentList[$parentId] > 0)
		{
			$departmentId = $parentList[$parentId];
			$parentId = 0;
			if ($parentList[$departmentId] > 0)
			{
				$parentId = $parentList[$departmentId];
			}

			$result = $this->isAllowDepartment($departmentId, $parentId, $allowDepartment, $parentList);
		}

		return $result;
	}

	public function getChildren(Item $parentItem, Dialog $dialog): void
	{
		$departmentId = (int)$parentItem->getId();
		$departmentRepository = ServiceContainer::getInstance()->departmentRepository();
		$departmentHead = $departmentRepository->getDepartmentHead($departmentId);

		$departments = $this->getStructure(['parentId' => $departmentId]);
		$this->fillDepartments($dialog, $departments);
		if ($this->getSelectMode() === self::MODE_DEPARTMENTS_ONLY)
		{
			return;
		}

		$headId = 0;

		if ($departmentHead)
		{
			$headId = $departmentHead->getId();
		}

		$userOptions = $this->getUserOptions($dialog);

		$items = UserProvider::makeItems(
			UserProvider::getUsers(['departmentId' => $departmentId] + $userOptions),
			$userOptions,
		);

		usort(
			$items,
			function(Item $a, Item $b) use ($headId) {
				if ($a->getId() === $headId)
				{
					return -1;
				}
				else if ($b->getId() === $headId)
				{
					return 1;
				}

				$lastNameA = $a->getCustomData()->get('lastName');
				$lastNameB = $b->getCustomData()->get('lastName');

				if (!empty($lastNameA) && !empty($lastNameB))
				{
					return $lastNameA > $lastNameB ? 1 : -1;
				}
				else if (empty($lastNameA) && !empty($lastNameB))
				{
					return 1;
				}
				else if (!empty($lastNameA) && empty($lastNameB))
				{
					return -1;
				}

				return $a->getTitle() > $b->getTitle() ? 1 : -1;
			}
		);

		if ($headId > 0)
		{
			foreach ($items as $item)
			{
				if ($item->getId() === $headId)
				{
					$item->getNodeOptions()->set('caption', Loc::getMessage('INTRANET_ENTITY_SELECTOR_MANAGER'));
					break;
				}
			}
		}

		$dialog->addItems($items);
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		if ($this->getSelectMode() === self::MODE_USERS_ONLY)
		{
			return;
		}

		$limit = $this->getLimit();

		// Try to select all departments
		$departments = $this->getStructure([
			'searchQuery'=> $searchQuery->getQuery(),
			'limit' => $limit,
		]);

		$limitExceeded = $limit <= $departments->count();
		if ($limitExceeded)
		{
			$searchQuery->setCacheable(false);
		}

		foreach ($departments as $department)
		{
			$isRootDepartment = $department->getDepthLevel() === 1;
			$hideRootDepartment = $isRootDepartment && !$this->options['allowSelectRootDepartment'];
			if ($hideRootDepartment)
			{
				continue;
			}

			$dialog->addItem(new Item([
				'id' => $department->getId(),
				'entityId' => 'department',
				'title' => $department->getName(),
			]));

			if ($this->getSelectMode() === self::MODE_USERS_AND_DEPARTMENTS && $this->options['allowFlatDepartments'])
			{
				$dialog->addItem(new Item([
					'id' => $department->getId().':F',
					'entityId' => 'department',
					'title' => Loc::getMessage('INTRANET_ENTITY_SELECTOR_ONLY_EMPLOYEES_MSGVER_1', [
						'#DEPARTMENT_NAME#' => $department->getName(),
					]),
				]));
			}
		}
	}

	public function getStructure(array $options = []): EO_Section_Collection
	{
		$structureIBlockId = self::getStructureIBlockId();
		if ($structureIBlockId <= 0)
		{
			return new EO_Section_Collection();
		}

		$filter = [
			'=IBLOCK_ID' => $structureIBlockId,
			'=ACTIVE' => 'Y',
		];

		if (!empty($options['searchQuery']) && is_string($options['searchQuery']))
		{
			$filter['?NAME'] = $options['searchQuery'];
		}

		if (!empty($options['parentId']) && is_int($options['parentId']))
		{
			$filter['=IBLOCK_SECTION_ID'] = $options['parentId'];
		}

		$limit = isset($options['limit']) && is_int($options['limit']) ? $options['limit'] : 100;

		if ($this->getOptions()['hideChatBotDepartment'])
		{
			$filter['!=XML_ID'] = 'im_bot';
		}

		$rootDepartment = null;
		$rootDepartmentId = self::getRootDepartmentId();
		if ($rootDepartmentId > 0)
		{
			$rootDepartment = SectionTable::getList([
				'select' => ['ID', 'LEFT_MARGIN', 'RIGHT_MARGIN', 'DEPTH_LEVEL'],
				'filter' => [
					'=ID' => $rootDepartmentId,
					'=IBLOCK_ID' => $structureIBlockId,
					'=ACTIVE' => 'Y',
				],
			])->fetchObject();

			if ($rootDepartment)
			{
				$filter['>=LEFT_MARGIN'] = $rootDepartment->getLeftMargin();
				$filter['<=RIGHT_MARGIN'] = $rootDepartment->getRightMargin();
			}
		}

		if (!empty($options['depthLevel']) && is_int($options['depthLevel']))
		{
			if ($rootDepartment)
			{
				$filter['<=DEPTH_LEVEL'] = $options['depthLevel'] + $rootDepartment->getDepthLevel();
			}
			else
			{
				$filter['<=DEPTH_LEVEL'] = $options['depthLevel'];
			}
		}

		return SectionTable::getList([
			'select' => ['ID', 'NAME', 'DEPTH_LEVEL', 'IBLOCK_SECTION_ID', 'LEFT_MARGIN', 'RIGHT_MARGIN'],
			'filter' => $filter,
			'order' => ['LEFT_MARGIN' => 'asc'],
			'limit' => $limit,
		])->fetchCollection();
	}

	protected function getSubdepartmentsCount($departmentId, ?int $limit = null)
	{
		return SectionTable::getList([
			'select' => [],
			'filter' => ['=IBLOCK_SECTION_ID' => $departmentId],
			'order' => [],
			'limit' => $limit ?? $this->getLimit(),
		])->fetchCollection()->count();
	}

	public static function getStructureIBlockId(): int
	{
		return (int)Option::get('intranet', 'iblock_structure', 0);
	}

	public static function getRootDepartmentId(): int
	{
		static $rootDepartmentId = null;

		if ($rootDepartmentId === null)
		{
			$rootDepartmentId = (int)Option::get('main', 'wizard_departament', false, SITE_ID);
		}

		return $rootDepartmentId;
	}

	public function getDepartments(array $ids, array $options = []): array
	{
		$structureIBlockId = self::getStructureIBlockId();
		if ($structureIBlockId <= 0)
		{
			return [];
		}

		$query = SectionTable::query();
		$query->setSelect(['ID', 'NAME', 'DEPTH_LEVEL', 'IBLOCK_SECTION_ID', 'LEFT_MARGIN', 'RIGHT_MARGIN']);
		$query->setOrder(['LEFT_MARGIN' => 'asc']);
		$query->where('IBLOCK_ID', $structureIBlockId);

		// ids = [1, '1:F', '10:F', '5:F', 5]
		$integerIds = array_map('intval', $ids);
		$idMap = array_combine($ids, $integerIds);
		$query->whereIn('ID', array_unique($integerIds));

		$active = isset($options['active']) ? $options['active'] : true;
		if (is_bool($active))
		{
			$query->where('ACTIVE', $active ? 'Y' : 'N');
		}

		$items = [];
		$departments = $query->exec()->fetchCollection();
		if ($departments->count() > 0)
		{
			foreach ($idMap as $id => $integerId)
			{
				$department = $departments->getByPrimary($integerId);
				if (!$department)
				{
					continue;
				}

				$isFlatDepartment = is_string($id) && $id[-1] === 'F';
				$availableInRecentTab = false;
				if ($isFlatDepartment)
				{
					$availableInRecentTab =
						$this->getSelectMode() === self::MODE_USERS_AND_DEPARTMENTS &&
						$this->options['allowFlatDepartments'] === true
					;
				}
				else
				{
					$availableInRecentTab = $this->getSelectMode() !== self::MODE_USERS_ONLY;
					if ($department->getDepthLevel() === 1 && !$this->options['allowSelectRootDepartment'])
					{
						$availableInRecentTab = false;
					}
				}

				$title = $isFlatDepartment
					? Loc::getMessage(
						'INTRANET_ENTITY_SELECTOR_ONLY_EMPLOYEES_MSGVER_1',
						['#DEPARTMENT_NAME#' => $department->getName()]
					)
					: $department->getName();

				$items[] = new Item([
					'id' => $id,
					'entityId' => 'department',
					'title' => $title,
					'availableInRecentTab' => $availableInRecentTab,
					'searchable' => $availableInRecentTab,
				]);
			}
		}

		return $items;
	}
}
