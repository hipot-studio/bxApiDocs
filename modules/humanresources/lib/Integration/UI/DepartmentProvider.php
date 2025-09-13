<?php

namespace Bitrix\HumanResources\Integration\UI;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\SelectionCondition\Node\NodeAccessFilter;
use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Config\Feature;
use Bitrix\HumanResources\Contract\Repository\NodeRepository;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\Direction;
use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Type\AccessCodeType;
use Bitrix\HumanResources\Type\IntegerCollection;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\HumanResources\Item\Collection\NodeCollection;
use Bitrix\HumanResources\Item\Collection\NodeMemberCollection;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Util\StructureHelper;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Integration\UI\EntitySelector\UserProvider;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;

/**
 * @see \Bitrix\Intranet\Integration\UI\EntitySelector\DepartmentProvider
 */
class DepartmentProvider extends BaseProvider
{
	public const MODE_USERS_AND_DEPARTMENTS = 'usersAndDepartments';
	public const MODE_USERS_ONLY = 'usersOnly';
	public const MODE_DEPARTMENTS_ONLY = 'departmentsOnly';

	public const NODE_ENTITY_TYPE_DEPARTMENT = 'department';
	public const NODE_ENTITY_TYPE_TEAM = 'team';

	private const INCLUDED_NODE_ENTITY_TYPES = [
		self::NODE_ENTITY_TYPE_DEPARTMENT => NodeEntityType::DEPARTMENT,
		self::NODE_ENTITY_TYPE_TEAM => NodeEntityType::TEAM,
	];

	public const ENTITY_ID = 'structure-node';

	private const TAB_ID_DEPARTMENTS = 'structure-departments-tab';
	private const TAB_ID_TEAMS = 'structure-teams-tab';
	private const TAB_ID_RECENT = 'recent';

	private const AVATAR_MODE_NONE = 'none';
	private const AVATAR_MODE_ITEM = 'item';
	private const AVATAR_MODE_NODE = 'node';
	private const AVATAR_MODE_BOTH = 'both';

	private const TAG_STYLE_MODE_DEFAULT = 'default';
	private const TAG_STYLE_MODE_NONE = 'none';

	private const IMAGE_DEPARTMENT_OPTION = '/bitrix/js/humanresources/entity-selector/src/images/department-option.svg';
	private const IMAGE_TEAM_OPTION = '/bitrix/js/humanresources/entity-selector/src/images/team-option.svg';
	private const IMAGE_TEAM = '/bitrix/js/humanresources/entity-selector/src/images/team.svg';
	private const IMAGE_DEPARTMENT = '/bitrix/js/humanresources/entity-selector/src/images/department.svg';
	private const IMAGE_COMPANY = '/bitrix/js/humanresources/entity-selector/src/images/company.svg';

	private int $limit = 100;
	private NodeRepository $nodeRepository;
	/** @var array<value-of<NodeEntityType>, Tab>|null */
	private ?array $entityTabsMap = null;
	private bool $showIcons = true;
	private string $avatarMode = self::AVATAR_MODE_BOTH;
	private string $tagStyle = self::TAG_STYLE_MODE_DEFAULT;

	public function __construct(array $options = [])
	{
		parent::__construct();

		$this->options['structureAction'] = null;
		$this->options['accessFilter'] = null;
		if ($options['restricted'] ?? false)
		{
			$this->options['structureAction'] = StructureAction::from($options['restricted']);
			$this->options['accessFilter'] = new NodeAccessFilter($this->options['structureAction']);
			$this->nodeRepository = Container::getPermissionRestrictedNodeRepository($this->options['structureAction']);
		}
		else
		{
			$this->nodeRepository = Container::getNodeRepository();
		}

		$this->options['active'] = isset($options['active']) && is_bool($options['active'])
			? $options['active']
			: true
		;

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

		$this->options['fillDepartmentsTab'] = true;
		if (isset($options['fillDepartmentsTab']) && is_bool($options['fillDepartmentsTab']))
		{
			$this->options['fillDepartmentsTab'] = $options['fillDepartmentsTab'];
		}

		$this->options['fillRecentTab'] = false;
		if (isset($options['fillRecentTab']) && is_bool($options['fillRecentTab']))
		{
			$this->options['fillRecentTab']
				= $options['fillRecentTab'] && $this->options['selectMode'] === self::MODE_DEPARTMENTS_ONLY;
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

		$this->options['forSearch'] = false;

		if (isset($options['forSearch']) && is_bool($options['forSearch']))
		{
			$this->options['forSearch'] = $options['forSearch'];
		}

		$this->options['flatMode'] = false;

		if (isset($options['flatMode']) && is_bool($options['flatMode']))
		{
			$this->options['flatMode'] = $options['flatMode'];
		}

		$this->options['includedNodeEntityTypes'] = [self::NODE_ENTITY_TYPE_DEPARTMENT];
		if (isset($options['includedNodeEntityTypes']) && is_array($options['includedNodeEntityTypes']))
		{
			$this->options['includedNodeEntityTypes'] = array_intersect(
				$options['includedNodeEntityTypes'],
				array_keys(self::INCLUDED_NODE_ENTITY_TYPES),
			);

			if (Feature::instance()->isCrossFunctionalTeamsAvailable())
			{
				$this->nodeRepository->setSelectableNodeEntityTypes(
					$this->getSelectableNodeEntityTypes(),
				);
			}
		}

		$this->options['useMultipleTabs'] = (bool)($options['useMultipleTabs'] ?? false);

		$this->initVisualOptions($options);
	}

	private function initVisualOptions(array $options): void
	{
		$visualOptions = $options['visual'] ?? [];

		$avatarMode = $visualOptions['avatarMode'] ?? self::AVATAR_MODE_BOTH;
		$avatarModes = [
			self::AVATAR_MODE_NONE,
			self::AVATAR_MODE_ITEM,
			self::AVATAR_MODE_NODE,
			self::AVATAR_MODE_BOTH,
		];
		if (!in_array($avatarMode, $avatarModes, true))
		{
			$avatarMode = self::AVATAR_MODE_BOTH;
		}
		$this->avatarMode = $avatarMode;

		$tagStyle = $visualOptions['tagStyle'] ?? self::TAG_STYLE_MODE_DEFAULT;
		$tagStyles = [
			self::TAG_STYLE_MODE_DEFAULT,
			self::TAG_STYLE_MODE_NONE,
		];
		if (!in_array($tagStyle, $tagStyles, true))
		{
			$tagStyle = self::TAG_STYLE_MODE_DEFAULT;
		}
		$this->tagStyle = $tagStyle;

		$this->showIcons = (bool)($visualOptions['showIcons'] ?? true);
	}

	public function isAvailable(): bool
	{
		if (!CurrentUser::get())
		{
			return false;
		}

		if (!Loader::includeModule('socialnetwork'))
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
		return $this->getDepartments($ids, true);
	}

	public function getChildren(Item $parentItem, Dialog $dialog): void
	{
		$parentNode = $this->nodeRepository->getById((int)$parentItem->getId());
		if ($parentNode === null)
		{
			return;
		}

		$includedTypes = $this->getIncludedNodeEntityTypes();
		if (!in_array(NodeEntityType::DEPARTMENT, $includedTypes, true))
		{
			$includedTypes[] = NodeEntityType::DEPARTMENT;
		}

		if ($this->options['useMultipleTabs'])
		{
			$includedTypes = [$parentNode->type];
		}

		$nodes = $this->getStructure([
			'parentId' => $parentNode->id,
			'nodeTypes' => $includedTypes,
			'depthLevel' => DepthLevel::FIRST,
		]);

		if (!$nodes->empty())
		{
			$this->fillNodes($dialog, $nodes);
		}

		if ($this->getSelectMode() === self::MODE_DEPARTMENTS_ONLY)
		{
			return;
		}

		$items = $this->makeUsersItemsForNode($parentNode, $dialog);

		$dialog->addItems($items);
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$selectMode = $dialog->getEntity(self::ENTITY_ID)?->getOptions()['selectMode'] ?? self::MODE_USERS_ONLY;
		if ($selectMode === self::MODE_USERS_ONLY)
		{
			return;
		}

		$limit = $this->getLimit();

		$nodes = $this->getStructure(
			[
				'searchQuery' => $searchQuery->getQuery(),
				'limit' => $limit,
				'nodeTypes' => $this->getIncludedNodeEntityTypes(),
			],
		);

		$limitExceeded = $limit <= $nodes->count();
		if ($limitExceeded)
		{
			$searchQuery->setCacheable(false);
		}

		foreach ($nodes as $node)
		{
			$isRootDepartment = (int)$node->parentId === 0;
			$hideRootDepartment = $isRootDepartment && !$this->options['allowSelectRootDepartment'];
			if ($hideRootDepartment)
			{
				continue;
			}

			$item = new Item(
				[
					'id' => $node->id,
					'entityId' => self::ENTITY_ID,
					'title' => $node->name,
					'customData' => [
						'accessCode' => $this->getSimpleNodeAccessCodeString($node),
					],
				],
			);
			$this->updateItemViewOptions($item, $node);
			$dialog->addItem($item);

			if ($selectMode === self::MODE_USERS_AND_DEPARTMENTS && $this->options['allowFlatDepartments'])
			{
				$dialog->addItem(
					new Item(
						[
							'id' => $this->addFlatModeValuePostfix($node->id ?? ''),
							'entityId' => self::ENTITY_ID,
							'title' => Loc::getMessage(
								'HUMANRESOURCES_ENTITY_SELECTOR_ONLY_EMPLOYEES_MSGVER_1',
								['#NODE_NAME#' => $node->name]
							),
							'customData' => [
								'accessCode' => $this->getSimpleNodeAccessCodeString($node) ?? '',
							],
						],
					),
				);
			}
		}
	}

	public function fillDialog(Dialog $dialog): void
	{
		$this->addTabsIntoDialog($dialog);

		if ($this->options['fillDepartmentsTab'] !== true && $this->options['fillRecentTab'] !== true)
		{
			return;
		}

		$nodes = $this->fetchNodes($this->getIncludedNodeEntityTypes());

		$hasMoreNodes = $this->existAnyChildrenForNodes($nodes);
		if ($this->getSelectMode() === self::MODE_USERS_ONLY || !$hasMoreNodes)
		{
			$entity = $dialog->getEntity('structure-node');
			$entity?->setDynamicSearch(false);
		}

		$forceDynamic = $this->getSelectMode() === self::MODE_DEPARTMENTS_ONLY && !$hasMoreNodes ? false : null;

		if ($this->options['fillRecentTab'] === true)
		{
			$this->fillRecentDepartments($dialog, $nodes);
		}

		if ($this->options['fillDepartmentsTab'] === true)
		{
			$this->fillNodes($dialog, $nodes, $forceDynamic);
		}
	}

	private function getSelectMode()
	{
		return $this->options['selectMode'];
	}

	private static function getSelectModes()
	{
		return [
			self::MODE_DEPARTMENTS_ONLY,
			self::MODE_USERS_ONLY,
			self::MODE_USERS_AND_DEPARTMENTS,
		];
	}

	private function getLimit(): int
	{
		return $this->limit;
	}

	private function getUserOptions(Dialog $dialog): array
	{
		if (isset($this->getOptions()['userOptions']) && is_array($this->getOptions()['userOptions']))
		{
			return $this->getOptions()['userOptions'];
		}
		if ($dialog->getEntity('user') && is_array($dialog->getEntity('user')->getOptions()))
		{
			return $dialog->getEntity('user')->getOptions();
		}

		return [];
	}

	private function getCurrentUserDepartments(): ?NodeMemberCollection
	{
		$currentUser = CurrentUser::get()->getId();

		if (!$currentUser || $this->options['allowOnlyUserDepartments'] !== true)
		{
			return new NodeMemberCollection();
		}

		return Container::getNodeMemberRepository()->findAllByEntityIdAndEntityType(
			$currentUser,
			MemberEntityType::USER,
		);
	}

	private function fillRecentDepartments(Dialog $dialog, NodeCollection $nodes)
	{
		foreach ($nodes as $node)
		{
			$isRootDepartment = (int)$node->parentId === 0;
			$hideRootDepartment = $isRootDepartment && !$this->options['allowSelectRootDepartment'];

			if ($hideRootDepartment && $isRootDepartment)
			{
				continue;
			}

			$item = new Item(
				[
					'id' => $node->id,
					'entityId' => self::ENTITY_ID,
					'title' => $node->name,
					'tabs' => self::TAB_ID_RECENT,
					'customData' => [
						'accessCode' => $this->getSimpleNodeAccessCodeString($node),
					],
				],
			);
			$this->updateItemViewOptions($item, $node);

			$dialog->addRecentItem($item);
		}
	}

	private function fillNodes(Dialog $dialog, NodeCollection $nodes, ?bool $forceDynamic = null)
	{
		/** @var array<int, Item> $parents */
		$parents = [];
		if ($this->options['allowOnlyUserDepartments'])
		{
			$currentDepartments = $this->getCurrentUserDepartments();
			$allowedNodeCollection = new NodeCollection();

			foreach ($currentDepartments as $currentDepartment)
			{
				$allowedNodeCollection->add(
					new Node(
						name: '',
						type: NodeEntityType::DEPARTMENT,
						structureId: 0,
						id: $currentDepartment->nodeId,
					),
				);
			}

			$nodes = $this->nodeRepository->getChildOfNodeCollection(
				nodeCollection: $allowedNodeCollection,
				depthLevel: DepthLevel::FULL,
			)->orderMapByInclude();
		}

		$selectMode = $dialog->getEntity(self::ENTITY_ID)?->getOptions()['selectMode'] ?? $this->getSelectMode();
		foreach ($nodes as $node)
		{
			$isRootDepartment = (int)$node->parentId === 0;
			$hideRootDepartment = $isRootDepartment && !$this->options['allowSelectRootDepartment'];

			$availableInRecentTab = $selectMode !== self::MODE_USERS_ONLY;

			$childDepartmentCount = null;
			if ($this->options['shouldCountSubdepartments'])
			{
				$childDepartmentCount = $this->nodeRepository->getChildOf($node->getId())->count();
			}

			$usersCount = null;
			if ($this->options['shouldCountUsers'])
			{
				$usersCount = $this->countUsersInNode(
					$node,
				);
			}

			$tabs = $this->getTabsForNode($node);
			if (empty($tabs))
			{
				continue;
			}

			$tabIds = array_map(static fn(Tab $tab): string => $tab->getId() ?? '', $tabs);
			$item = new Item(
				[
					'id' => $node->id,
					'entityId' => self::ENTITY_ID,
					'title' => $node->name,
					'tabs' => $tabIds,
					'searchable' => $availableInRecentTab,
					'availableInRecentTab' => $availableInRecentTab,
					'customData' => [
						'subdepartmentsCount' => $childDepartmentCount,
						'usersCount' => $usersCount,
						'accessCode' => $this->getSimpleNodeAccessCodeString($node),
					],
					'nodeOptions' => [
						'dynamic' => !$this->options['flatMode'] && (!is_bool($forceDynamic) || $forceDynamic),
						'open' => $isRootDepartment,
					],
				],
			);
			$this->updateItemViewOptions($item, $node);

			if ($selectMode === self::MODE_DEPARTMENTS_ONLY && !$hideRootDepartment)
			{
				$this->addSelectableNodeChildItemIfNeed($item, $node);
			}
			elseif ($selectMode === self::MODE_USERS_AND_DEPARTMENTS)
			{
				$this->fillNodesForUserAndDepartments($hideRootDepartment, $item, $node);
			}

			if ($this->options['flatMode'])
			{
				$dialog->addItem($item);

				continue;
			}

			$parentItem = $parents[$node->parentId] ?? null;
			$parentNode = $nodes->getItemById($node->parentId);
			if ($parentItem && ($parentNode->type === $node->type || !$this->options['useMultipleTabs']))
			{
				$parentItem->addChild($item);
			}
			else
			{
				$dialog->addItem($item);
			}

			$parents[$node->id] = $item;
		}
	}

	private function addSelectableNodeChildItemIfNeed(Item $item, Node $node): void
	{
		$nodeTitle = $node->isTeam()
			? Loc::getMessage('HUMANRESOURCES_ENTITY_SELECTOR_SELECT_TEAM')
			: Loc::getMessage('HUMANRESOURCES_ENTITY_SELECTOR_SELECT_DEPARTMENT');

		if (
			!$this->options['useMultipleTabs']
			|| $node->isTeam()
			|| $node->isDepartment()
		)
		{
			$item->addChild(
				new Item(
					[
						'id' => $node->id,
						'title' => $node->name,
						'entityId' => self::ENTITY_ID,
						'nodeOptions' => [
							'title' => $nodeTitle,
							'renderMode' => 'override',
						],
						'customData' => ['accessCode' => $this->getSimpleNodeAccessCodeString($node)],
					],
				),
			);
		}
	}

	private function fillNodesForUserAndDepartments(bool $hideRootDepartment, Item $item, Node $node): void
	{
		if (!$hideRootDepartment && !$this->options['flatMode'])
		{
			$avatar = null;
			$title = null;

			if ($node->isDepartment())
			{
				$avatar = self::IMAGE_DEPARTMENT_OPTION;
				$title = Loc::getMessage(
					$this->options['forSearch']
						? 'HUMANRESOURCES_ENTITY_SELECTOR_ALL_EMPLOYEES_SELECT'
						: 'HUMANRESOURCES_ENTITY_SELECTOR_ALL_EMPLOYEES_SUBDIVISIONS',
				);
			}
			elseif ($node->isTeam())
			{
				$avatar = self::IMAGE_TEAM_OPTION;
				$title = Loc::getMessage('HUMANRESOURCES_ENTITY_SELECTOR_ALL_TEAM_EMPLOYEES_SUBTEAMS');
			}

			$item->addChild(
				new Item(
					[
						'id' => $node->id,
						'title' => $node->name,
						'entityId' => self::ENTITY_ID,
						'nodeOptions' => [
							'title' => $title,
							'avatar' => $avatar,
							'renderMode' => 'override',
						],
						'customData' => [
							'accessCode' => $this->getRecursiveAccessCodeString($node),
						],
					],
				),
			);
		}

		if ($this->options['allowFlatDepartments'] && !$this->options['flatMode'])
		{
			if ($node->isDepartment())
			{
				$item->addChild(
					new Item(
						[
							'id' => $this->addFlatModeValuePostfix($node->id ?? ''),
							'entityId' => self::ENTITY_ID,
							'title' => Loc::getMessage(
								'HUMANRESOURCES_ENTITY_SELECTOR_ONLY_EMPLOYEES_MSGVER_1',
								['#NODE_NAME#' => $node->name]
							),
							'nodeOptions' => [
								'title' => Loc::getMessage(
									'HUMANRESOURCES_ENTITY_SELECTOR_ONLY_DEPARTMENT_EMPLOYEES',
								),
								'avatar' => self::IMAGE_DEPARTMENT_OPTION,
								'renderMode' => 'override',
							],
							'customData' => [
								'accessCode' => $this->addFlatModeValuePostfix($this->getSimpleNodeAccessCodeString($node) ?? ''),
							],
						],
					),
				);
			}
			elseif ($node->isTeam())
			{
				$item->addChild(
					new Item(
						[
							'id' => $this->addFlatModeValuePostfix($node->id ?? ''),
							'entityId' => self::ENTITY_ID,
							'title' => Loc::getMessage(
								'HUMANRESOURCES_ENTITY_SELECTOR_ONLY_EMPLOYEES_MSGVER_1',
								['#NODE_NAME#' => $node->name]
							),
							'nodeOptions' => [
								'title' => Loc::getMessage('HUMANRESOURCES_ENTITY_SELECTOR_ONLY_TEAM_EMPLOYEES'),
								'avatar' => self::IMAGE_TEAM_OPTION,
								'renderMode' => 'override',
							],
							'customData' => [
								'accessCode' => $this->addFlatModeValuePostfix($this->getSimpleNodeAccessCodeString($node) ?? ''),
							],
						],
					),
				);
			}
		}
	}

	/**
	 * @param array{
	 *     limit?: int,
	 *     active?: NodeActiveFilter,
	 *     searchQuery?: string|null,
	 *     parentId?: int|null,
	 *     depthLevel?: int|null,
	 *     nodeTypes?: list<NodeEntityType>|null
	 * } $options
	 */
	private function getStructure(array $options = []): NodeCollection
	{
		$limit = isset($options['limit']) && is_int($options['limit']) ? $options['limit'] : 100;

		$structure = StructureHelper::getDefaultStructure();

		if ($structure?->id === null)
		{
			return new NodeCollection();
		}

		$nodeBuilder = new NodeDataBuilder();
		$nodeBuilder->setLimit($limit);
		$searchQuery = isset($options['searchQuery']) && is_string($options['searchQuery'])
			? $options['searchQuery']
			: null
		;

		$depthLevel =
			isset($options['depthLevel'])
			&& (is_int($options['depthLevel']) || $options['depthLevel'] instanceof DepthLevel)
				? $options['depthLevel']
				: DepthLevel::FULL
		;

		$nodeTypes = [];
		if (isset($options['nodeTypes']))
		{
			$nodeTypes = $options['nodeTypes'];
		}

		if (isset($options['parentId']))
		{
			$idFilter = IdFilter::fromId($options['parentId']);
			if (!isset($options['depthLevel']))
			{
				$depthLevel = DepthLevel::WITHOUT_PARENT;
			}
		}

		$node = $nodeBuilder
			->addFilter(
				new NodeFilter(
					idFilter: $idFilter ?? null,
					entityTypeFilter: NodeTypeFilter::fromNodeTypes($nodeTypes),
					structureId: $structure->id,
					direction: Direction::CHILD,
					depthLevel: $depthLevel,
					active: $this->options['active'],
					accessFilter: $this->options['accessFilter'],
					name: $searchQuery,
				)
			)
			->getAll()
			->orderMapByInclude()
		;

		return $node;
	}

	/**
	 * @param array<int|string> $ids
	 * @param array $options
	 * @return array
	 */
	public function getDepartments(array $ids, bool $useViewRule = false): array
	{
		$integerIds = array_map(intval(...), $ids);
		$idMap = array_combine($ids, $integerIds);
		$structure = StructureHelper::getDefaultStructure();

		$items = [];
		$nodeBuilder = new NodeDataBuilder();
		if ($useViewRule)
		{
			$nodes = $nodeBuilder
				->addFilter(
					new NodeFilter(
						idFilter: new IdFilter(new IntegerCollection(...$integerIds)),
						entityTypeFilter: NodeTypeFilter::fromNodeTypes($this->getIncludedNodeEntityTypes()),
						structureId: $structure?->id,
						active: $this->options['active'],
						accessFilter: new NodeAccessFilter(StructureAction::ViewAction),
					)
				)
				->getAll()
				->orderMapByInclude()
			;

			$rootDepartment = $this->getRootDepartment();
			if (
				in_array($rootDepartment?->id, $integerIds, true)
				&& !$nodes->getItemById($rootDepartment->id)
			)
			{
				$nodes->add($rootDepartment);
			}
		}
		else
		{
			$nodes = $nodeBuilder
				->addFilter(
					new NodeFilter(
						idFilter: new IdFilter(new IntegerCollection(...$integerIds)),
						entityTypeFilter: NodeTypeFilter::fromNodeTypes($this->getIncludedNodeEntityTypes()),
						structureId: $structure?->id,
						active: $this->options['active'],
						accessFilter: $this->options['accessFilter'],
					)
				)
				->getAll()
				->orderMapByInclude()
			;
		}

		if ($nodes->count() > 0)
		{
			foreach ($idMap as $id => $integerId)
			{
				$node = $nodes->getItemById($integerId);
				if (!$node)
				{
					continue;
				}

				$isFlatDepartment = is_string($id) && $id[-1] === 'F';
				if ($isFlatDepartment)
				{
					$availableInRecentTab
						= $this->getSelectMode() === self::MODE_USERS_AND_DEPARTMENTS
						&& $this->options['allowFlatDepartments'] === true;
				}
				else
				{
					$availableInRecentTab = $this->getSelectMode() !== self::MODE_USERS_ONLY;
					if ($node->depth === 1 && !$this->options['allowSelectRootDepartment'])
					{
						$availableInRecentTab = false;
					}
				}

				$title = $isFlatDepartment
					? Loc::getMessage(
						'HUMANRESOURCES_ENTITY_SELECTOR_ONLY_EMPLOYEES_MSGVER_1',
						[
							'#NODE_NAME#' => $node->name,
						],
					)
					: $node->name;

				$item = new Item(
					[
						'id' => $id,
						'entityId' => self::ENTITY_ID,
						'title' => $title,
						'availableInRecentTab' => $availableInRecentTab,
						'searchable' => $availableInRecentTab,
						'customData' => [
							'accessCode' => $this->getSimpleNodeAccessCodeString($node),
						],
					],
				);
				$this->updateItemViewOptions($item, $node);

				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * Add postfix (only employees) to department value in flat mode
	 * @return string
	 */
	private function addFlatModeValuePostfix(string|int $value): string
	{
		return $value . ':F';
	}

	private function getRootDepartment(): ?Node
	{
		static $rootDepartment = null;

		if ($rootDepartment === null)
		{
			$structure = StructureHelper::getDefaultStructure();
			$rootDepartment = $this->nodeRepository->getRootNodeByStructureId($structure->id);
		}

		return $rootDepartment;
	}

	/**
	 * @return list<NodeEntityType>
	 */
	private function getIncludedNodeEntityTypes(): array
	{
		$includedTypes = array_map(
			fn(string $includedType) => static::INCLUDED_NODE_ENTITY_TYPES[$includedType],
			$this->options['includedNodeEntityTypes'],
		);

		if (
			!Feature::instance()->isCrossFunctionalTeamsAvailable()
			&& in_array(
				NodeEntityType::TEAM,
				$includedTypes,
				true,
			)
		)
		{
			$includedTypes = array_filter(
				$includedTypes,
				fn(NodeEntityType $type) => $type !== NodeEntityType::TEAM,
			);
		}

		return $includedTypes;
	}

	private function updateItemViewOptions(Item $item, Node $node): void
	{
		if ($node->isTeam())
		{
			$this->applyTeamTagOptions($item);

			return;
		}

		$isRoot = (int)$node->parentId === 0;
		$this->applyDepartmentTagOptions($item, $isRoot);
	}

	private function applyTeamTagOptions(
		Item $item,
	): void
	{
		$tagOptions = $item->getTagOptions();
		$isDefaultTagStyle = $this->tagStyle === self::TAG_STYLE_MODE_DEFAULT;

		$item->setSupertitle(Loc::getMessage('HUMANRESOURCES_ENTITY_SELECTOR_TEAM_SUPER_TITLE'));

		if ($this->showIcons)
		{
			$icon = self::IMAGE_TEAM;
			$this->placeAvatar($item, $icon, $this->avatarMode);
			if ($isDefaultTagStyle)
			{
				$tagOptions->set('avatar', $icon);
			}
		}

		if ($isDefaultTagStyle)
		{
			$tagOptions->set('fontWeight', '700');
			$tagOptions->set('bgColor', 'rgba(0, 117, 255, 0.12)');
			$tagOptions->set('textColor', 'rgba(0, 117, 255, 0.69)');
		}
	}

	private function applyDepartmentTagOptions(
		Item $item,
		bool $isRoot,
	): void
	{
		$isDefaultTagStyle = $this->tagStyle === self::TAG_STYLE_MODE_DEFAULT;
		$tagOptions = $item->getTagOptions();

		$item->setSupertitle(Loc::getMessage('HUMANRESOURCES_ENTITY_SELECTOR_DEPARTMENT_SUPER_TITLE'));
		if ($this->showIcons)
		{
			$icon = $isRoot
				? self::IMAGE_COMPANY
				: self::IMAGE_DEPARTMENT;

			$this->placeAvatar($item, $icon, $this->avatarMode);
			if ($isDefaultTagStyle)
			{
				$tagOptions->set('avatar', $icon);
			}
		}

		if ($isDefaultTagStyle)
		{
			$tagOptions->set('fontWeight', '700');
			if ($isRoot)
			{
				$tagOptions->set('bgColor', '#F1FBD0');
				$tagOptions->set('textColor', '#7FA800');
			}
			else
			{
				$tagOptions->set('bgColor', '#ade7e4');
				$tagOptions->set('textColor', '#207976');
			}
		}
	}

	/**
	 * @param self::AVATAR_MODE_* $mode
	 */
	private function placeAvatar(Item $item, string $path, string $mode): void
	{
		if ($mode === self::AVATAR_MODE_NONE)
		{
			return;
		}

		if ($mode === self::AVATAR_MODE_ITEM || $mode === self::AVATAR_MODE_BOTH)
		{
			$item->setAvatar($path);
		}

		if ($mode === self::AVATAR_MODE_NODE || $mode === self::AVATAR_MODE_BOTH)
		{
			$item->getNodeOptions()->set('avatar', $path);
		}
	}

	/**
	 * @return array<value-of<NodeEntityType>, Tab>
	 */
	private function getEntityTabsMap(): array
	{
		if ($this->entityTabsMap === null)
		{
			$this->entityTabsMap = [
				NodeEntityType::DEPARTMENT->value => $this->createTab(
					self::TAB_ID_DEPARTMENTS,
					Loc::getMessage('HUMANRESOURCES_ENTITY_SELECTOR_DEPARTMENTS_TAB_TITLE') ?? '',
					$this->getDepartmentTabIconInBase64(),
					$this->getDepartmentTabSelectedIconInBase64(),
				),
				NodeEntityType::TEAM->value => $this->createTab(
					self::TAB_ID_TEAMS,
					Loc::getMessage('HUMANRESOURCES_ENTITY_SELECTOR_TEAMS_TAB_TITLE') ?? '',
					$this->getTeamTabIconInBase64(),
					$this->getTeamTabSelectedIconInBase64(),
				),
			];
		}

		return $this->entityTabsMap;
	}

	private function createTab(string $tabId, string $tabTitle, string $tabIconDefault, string $tabIconSelected): Tab
	{
		return new Tab(
			[
				'id' => $tabId,
				'title' => $tabTitle,
				'itemMaxDepth' => 7,
				'icon' => [
					'default' => $tabIconDefault,
					'selected' => $tabIconSelected,
				],
			],
		);
	}

	private function getDepartmentTabIconInBase64(): string
	{
		return 'data:image/svg+xml;charset=US-ASCII,%3Csvg%20width%3D%2223%22%20height%3D%2223%22%20fill%3D%22'
			. 'none%22%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%3E%3Cpath%20d%3D%22M15.953%2018.654a29.847%'
			. '2029.847%200%2001-6.443.689c-2.672%200-5.212-.339-7.51-.948.224-1.103.53-2.573.672-3.106.238-.896'
			. '%201.573-1.562%202.801-2.074.321-.133.515-.24.71-.348.193-.106.386-.213.703-.347.036-.165.05-.333.'
			. '043-.5l.544-.064s.072.126-.043-.614c0%200-.61-.155-.64-1.334%200%200-.458.148-.486-.566a1.82%201.'
			. '82%200%2000-.08-.412c-.087-.315-.164-.597.233-.841l-.287-.74S5.87%204.583%207.192%204.816c-.537-.'
			. '823%203.99-1.508%204.29%201.015.119.76.119%201.534%200%202.294%200%200%20.677-.075.225%201.17%200'
			. '%200-.248.895-.63.693%200%200%20.062%201.133-.539%201.325%200%200%20.043.604.043.645l.503.074s-.01'
			. '4.503.085.557c.458.287.96.505%201.488.645%201.561.383%202.352%201.041%202.352%201.617%200%200%20.6'
			. '41%202.3.944%203.802z%22%20fill%3D%22%23ABB1B8%22/%3E%3Cpath%20d%3D%22M21.47%2016.728c-.36.182-.73'
			. '.355-1.112.52h-3.604c-.027-.376-.377-1.678-.58-2.434-.081-.299-.139-.513-.144-.549-.026-.711-1.015-'
			. '1.347-2.116-1.78a1.95%201.95%200%2000.213-.351c.155-.187.356-.331.585-.42l.017-.557-1.208-.367s-.31'
			. '-.14-.342-.14c.036-.086.08-.168.134-.245.023-.06.17-.507.17-.507-.177.22-.383.415-.614.58.211-.363.'
			. '39-.743.536-1.135a7.02%207.02%200%2000.192-1.15%2016.16%2016.16%200%2001.387-2.093c.125-.343.346-.64'
			. '7.639-.876a3.014%203.014%200%20011.46-.504h.062c.525.039%201.03.213%201.462.504.293.229.514.532.64.8'
			. '76.174.688.304%201.387.387%202.092.037.38.104.755.201%201.124.145.4.322.788.527%201.161a3.066%203.06'
			. '6%200%2001-.614-.579s.113.406.136.466c.063.09.119.185.167.283-.03%200-.342.141-.342.141l-1.208.367.0'
			. '17.558c.23.088.43.232.585.419.073.179.188.338.337.466.292.098.573.224.84.374.404.219.847.36%201.306.'
			. '416.463.074.755.8.755.8l.037.729.093%201.811z%22%20fill%3D%22%23ABB1B8%22/%3E%3C/svg%3E';
	}

	private function getDepartmentTabSelectedIconInBase64(): string
	{
		return str_replace('ABB1B8', 'fff', $this->getDepartmentTabIconInBase64());
	}

	private function getTeamTabIconInBase64(): string
	{
		return 'data:image/svg+xml;charset=US-ASCII,%3Csvg%20width%3D%2219%22%20height%3D%2216%22%20viewBox%3D%220%200%2019%2016%22%20fill%3D%22none%22%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%3E%3Cpath%20d%3D%22M12.4873%204.80859C12.0867%204.17389%2015.465%203.64647%2015.6895%205.58984C15.7777%206.17552%2015.7777%206.77078%2015.6895%207.35645C15.6989%207.35544%2016.1909%207.3077%2015.8574%208.25781C15.8574%208.25781%2015.6715%208.94875%2015.3867%208.79395C15.3876%208.81186%2015.4282%209.66794%2014.9844%209.81445C14.9846%209.81741%2015.0164%2010.2772%2015.0166%2010.3105L15.3916%2010.3672C15.3912%2010.3808%2015.3816%2010.7557%2015.4551%2010.7969C15.797%2011.0176%2016.1722%2011.185%2016.5664%2011.293C17.7302%2011.5884%2018.3212%2012.0952%2018.3213%2012.5391L18.5303%2013.6025C18.5907%2013.9104%2018.4267%2014.221%2018.1318%2014.3281C16.9051%2014.7739%2015.5215%2015.0378%2014.0547%2015.0693H13.4619C11.9878%2015.0377%2010.5975%2014.7711%209.36621%2014.3213C9.08415%2014.2181%208.91974%2013.9273%208.96777%2013.6309C9.0136%2013.348%209.06446%2013.0768%209.11621%2012.875C9.29341%2012.1846%2010.2894%2011.6717%2011.2061%2011.2773C11.4459%2011.1741%2011.591%2011.092%2011.7373%2011.0088C11.8803%2010.9275%2012.0249%2010.8454%2012.2607%2010.7422C12.2875%2010.615%2012.2985%2010.4842%2012.293%2010.3545L12.6992%2010.3066C12.701%2010.3098%2012.7512%2010.3934%2012.667%209.83301C12.667%209.83301%2012.2106%209.71495%2012.1895%208.80664C12.1895%208.80664%2011.8459%208.92046%2011.8252%208.37012C11.8209%208.2607%2011.7927%208.15549%2011.7656%208.05469C11.7005%207.81248%2011.642%207.5952%2011.9385%207.40625L11.7246%206.83496C11.7224%206.81325%2011.5048%204.63094%2012.4873%204.80859ZM6.47168%2011.8018H1.22266C0.946516%2011.8018%200.72266%2011.5779%200.722656%2011.3018V10.0254C0.722656%209.74925%200.946514%209.52539%201.22266%209.52539H8.31934L6.47168%2011.8018ZM9.06152%207.14941H1.22266C0.946516%207.14941%200.72266%206.92555%200.722656%206.64941V5.37305C0.722656%205.0969%200.946514%204.87305%201.22266%204.87305H9.38672L9.06152%207.14941ZM14.2393%200.22168C14.5152%200.221912%2014.7393%200.445681%2014.7393%200.72168V1.99805C14.7393%202.23139%2014.5786%202.42532%2014.3623%202.48047V2.47949H9.72852L9.72559%202.49805H1.22266C0.946516%202.49805%200.72266%202.27419%200.722656%201.99805V0.72168C0.722656%200.445537%200.946514%200.22168%201.22266%200.22168H14.2393Z%22%20fill%3D%22%23ABB1B8%22/%3E%3C/svg%3E';
	}

	private function getTeamTabSelectedIconInBase64(): string
	{
		return str_replace('ABB1B8', 'fff', $this->getTeamTabIconInBase64());
	}

	private function addTabsIntoDialog(Dialog $dialog): void
	{
		$tabs = $this->options['useMultipleTabs']
			? $this->getIncludedTypeTabs()
			: $this->getSingleTypeTabs();

		foreach ($tabs as $tab)
		{
			$dialog->addTab($tab);
		}
	}

	/**
	 * @return list<Tab>
	 */
	private function getIncludedTypeTabs(): array
	{
		$entityTabsMap = $this->getEntityTabsMap();
		$tabs = [];

		foreach ($this->getIncludedNodeEntityTypes() as $type)
		{
			if (isset($entityTabsMap[$type->value]))
			{
				$tabs[] = $entityTabsMap[$type->value];
			}
		}

		return $tabs;
	}

	/**
	 * @return list<Tab>
	 */
	private function getSingleTypeTabs(): array
	{
		$entityTabsMap = $this->getEntityTabsMap();
		$included = $this->getIncludedNodeEntityTypes();

		$firstEntityTab = $entityTabsMap[$included[0]?->value] ?? null;
		if (count($included) === 1 && $firstEntityTab !== null)
		{
			return [$firstEntityTab];
		}

		return [$entityTabsMap[NodeEntityType::DEPARTMENT->value]];
	}

	/**
	 * @return list<Tab>
	 */
	private function getTabsForNode(Node $node): array
	{
		$entityTabsMap = $this->getEntityTabsMap();
		if ($this->options['useMultipleTabs'] !== true || !array_key_exists($node->type->value, $entityTabsMap))
		{
			return [$entityTabsMap[NodeEntityType::DEPARTMENT->value]];
		}

		$includedEntityTypes = $this->getIncludedNodeEntityTypes();
		if ($node->isTeam() && in_array(NodeEntityType::TEAM, $includedEntityTypes, true))
		{
			return [$entityTabsMap[NodeEntityType::TEAM->value]];
		}
		if ($node->isDepartment() && in_array(NodeEntityType::DEPARTMENT, $includedEntityTypes, true))
		{
			return [$entityTabsMap[NodeEntityType::DEPARTMENT->value]];
		}

		return [];
	}

	/**
	 * @return list<NodeEntityType>
	 */
	private function getSelectableNodeEntityTypes(): array
	{
		if (Feature::instance()->isCrossFunctionalTeamsAvailable())
		{
			return [NodeEntityType::DEPARTMENT];
		}

		$nodeEntityTypes = $this->getIncludedNodeEntityTypes();
		if (empty($nodeEntityTypes))
		{
			return $nodeEntityTypes;
		}

		if (!in_array(NodeEntityType::DEPARTMENT, $nodeEntityTypes, true))
		{
			$nodeEntityTypes[] = NodeEntityType::DEPARTMENT;
		}

		return $nodeEntityTypes;
	}

	private function hasDepartmentTab(): bool
	{
		return in_array(
			NodeEntityType::DEPARTMENT,
			$this->getIncludedNodeEntityTypes(),
			true,
		);
	}

	/**
	 * @param list<NodeEntityType> $includedTypes
	 */
	private function fetchNodes(array $includedTypes): NodeCollection
	{
		if (!$this->options['flatMode'] && !$this->options['useMultipleTabs'])
		{
			return $this->getStructure(
				['depthLevel' => $this->options['depthLevel'], 'nodeTypes' => $includedTypes],
			);
		}

		$nodes = new NodeCollection();
		$count = count($includedTypes);
		if ($count === 0)
		{
			return $nodes;
		}

		$limitPerType = $this->getLimit() / $count;
		foreach ($includedTypes as $includedType)
		{
			if ($this->options['flatMode'])
			{
				$nodes = $this
					->getStructure(
						[
							'limit' => $limitPerType,
							'nodeTypes' => [$includedType],
						],
					)
					->merge($nodes)
				;

				continue;
			}

			if ($includedType === NodeEntityType::DEPARTMENT)
			{
				$nodes = $this
					->getStructure(
						['depthLevel' => $this->options['depthLevel'], 'nodeTypes' => [$includedType]],
					)
					->merge($nodes)
				;
			}
			if ($includedType === NodeEntityType::TEAM)
			{
				$nodes = $this
					->getStructure(
						['nodeTypes' => [NodeEntityType::TEAM]],
					)
					->merge($nodes)
				;
			}
		}

		return $nodes;
	}

	/**
	 * @todo refactor it to reuse logic in other places
	 */
	private function getRecursiveAccessCodeString(Node $node): string
	{
		$accessCodeType = match ($node->type)
		{
			NodeEntityType::TEAM => AccessCodeType::HrTeamRecursiveType,
			default => AccessCodeType::HrDepartmentRecursiveType,
		};

		return $accessCodeType->buildAccessCode($node->id ?? 0);
	}

	private function getSimpleNodeAccessCodeString(Node $node): string
	{
		$accessCodeType = match ($node->type)
		{
			NodeEntityType::TEAM => AccessCodeType::HrTeamType,
			default => AccessCodeType::HrDepartmentType,
		};

		return $accessCodeType->buildAccessCode($node->id ?? 0);
	}

	/**
	 * @return list<Item>
	 */
	private function makeUsersItemsForNode(Node $parentNode, Dialog $dialog): array
	{
		$userOptions = $this->getUserOptions($dialog);

		$userItems = $this->fetchUsersItemsForNode($parentNode, $userOptions);

		$headMembers = Container::getNodeMemberService()->getDefaultHeadRoleEmployees($parentNode->id);
		$headIds = array_map(
			static fn($member) => $member->entityId,
			$headMembers->getItemMap(),
		);

		foreach ($userItems as $userItem)
		{
			if (in_array($userItem->getId(), $headIds))
			{
				$userItem->getNodeOptions()->set('caption', Loc::getMessage('HUMANRESOURCES_ENTITY_SELECTOR_MANAGER'));

				break;
			}
		}

		return $userItems;
	}

	/**
	 * @return list<Item>
	 */
	private function fetchUsersItemsForNode(Node $node, array $userOptions): array
	{
		$nodeUserIds = NodeMemberDataBuilder::createWithFilter(
			new NodeMemberFilter(
				entityType: MemberEntityType::USER,
				nodeFilter: NodeFilter::createWithNodeId($node->id),
			),
		)
			->getAll()
			->getEntityIds()
		;

		if (empty($nodeUserIds))
		{
			return [];
		}

		return UserProvider::makeItems(
			UserProvider::getUsers(
				['userId' => $nodeUserIds]
				+ $userOptions,
			),
			$userOptions,
		);
	}

	private function countUsersInNode(Node $node): int
	{
		$nodeMembers = NodeMemberDataBuilder::createWithFilter(
			new NodeMemberFilter(
				entityType: MemberEntityType::USER,
				nodeFilter: NodeFilter::createWithNodeId($node->id),
			),
		)
			->getAll()
		;

		return $nodeMembers->count();
	}

	private function existAnyChildrenForNodes(NodeCollection $fetchedNodes): bool
	{
		$bottomNodesIds = $fetchedNodes->getBottomNodes()->getIds();
		if (empty($bottomNodesIds))
		{
			return false;
		}

		$childNodeByIds
			= NodeDataBuilder::createWithFilter(
				new NodeFilter(
					idFilter: idFilter::fromIds($bottomNodesIds),
					direction: Direction::CHILD,
					depthLevel: DepthLevel::FIRST,
				),
			)
			->get()
		;

		return !is_null($childNodeByIds);
	}
}
