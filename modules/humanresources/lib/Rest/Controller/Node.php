<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Rest\Controller;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\SelectionCondition\Node\NodeAccessFilter;
use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\HumanResources\Builder\Structure\Sort\NodeSort;
use Bitrix\HumanResources\Command\Structure\Node\CreateNodeCommand;
use Bitrix\HumanResources\Command\Structure\Node\Enum\UserAddStrategy;
use Bitrix\HumanResources\Config\Feature;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\Direction;
use Bitrix\HumanResources\Enum\SortDirection;
use Bitrix\HumanResources\Exception\CommandException;
use Bitrix\HumanResources\Exception\CommandValidateException;
use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Item\Node as NodeItem;
use Bitrix\HumanResources\Rest\Trait\NodeControllerTrait;
use Bitrix\HumanResources\Rest\Dto\NodeDto;
use Bitrix\HumanResources\Rest\RequestParams;
use Bitrix\HumanResources\Rest\Dto\NodeMemberDto;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\HumanResources\Util\StructureHelper;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

/**
 * Node operations: get, list, search, children, count, add, edit, move.
 *
 * @see \Bitrix\HumanResources\Controller\Structure\Node — existing ajax controller
 * @see \Bitrix\HumanResources\Controller\Structure\Department — department-specific ajax controller
 * @see \Bitrix\HumanResources\Controller\Structure\Team — team-specific ajax controller
 */
#[DtoType(NodeDto::class)]
class Node extends RestController
{
	use NodeControllerTrait;

	private const DEFAULT_LIMIT = 50;
	private const MAX_LIMIT = 200;

	protected function init(): void
	{
		parent::init();
		$this->initNodeContext();
	}

	/**
	 * Get node by ID with members.
	 *
	 * @restMethod humanresources.node.get
	 * @see \Bitrix\HumanResources\Controller\Structure\Node::getAction
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeShowTool
	 */
	public function getAction(GetRequest $request): GetResponse
	{
		$nodeId = (int)$request->id;

		$node = $this->requireNodeById($nodeId);

		$this->checkNodeViewAccess($node);

		$dto = $this->mapNodeToDto($node);
		$dto->members = $this->getNodeMembers($node);

		return new GetResponse($dto);
	}

	/**
	 * List nodes by type with pagination.
	 *
	 * @restMethod humanresources.node.list
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeListTool
	 *
	 * Request params:
	 * - string type  Node type: "DEPARTMENT" or "TEAM" (required)
	 */
	public function listAction(ListRequest $request): ListResponse
	{
		$type = $this->getNodeTypeFromRequest();

		$limit = self::DEFAULT_LIMIT;
		$offset = 0;

		if ($request->pagination !== null)
		{
			$limit = min($request->pagination->getLimit(), self::MAX_LIMIT);
			$offset = $request->pagination->getOffset();
		}

		$defaultStructure = StructureHelper::getDefaultStructure();

		if ($defaultStructure === null)
		{
			return new ListResponse(new DtoCollection(NodeDto::class));
		}

		$nodes = (new NodeDataBuilder())
			->addFilter(
				new NodeFilter(
					entityTypeFilter: NodeTypeFilter::fromNodeType($type),
					structureId: $defaultStructure->id,
					active: true,
					accessFilter: new NodeAccessFilter(StructureAction::ViewAction, $this->userId),
				),
			)
			->setSort(new NodeSort(sort: SortDirection::Asc))
			->setLimit($limit)
			->setOffset($offset)
			->getAll()
		;

		$collection = new DtoCollection(NodeDto::class);

		foreach ($nodes as $node)
		{
			$collection->add($this->mapNodeToDto($node));
		}

		return new ListResponse($collection);
	}

	/**
	 * Search nodes by name.
	 *
	 * @restMethod humanresources.node.search
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeSearchTool
	 * @see \Bitrix\HumanResources\Integration\UI\DepartmentProvider::doSearch — similar search in entity selector
	 *
	 * Request params:
	 * - string type      Node type: "DEPARTMENT" or "TEAM" (required)
	 * - string name      Search query (required)
	 * - int    parentId  Filter by parent node (optional)
	 */
	public function searchAction(ListRequest $request): ListResponse
	{
		$params = new RequestParams($this->getRequest()->getJsonList());
		$name = $params->requireString('name');
		$type = $this->getNodeTypeFromRequest();
		$parentId = $params->getInt('parentId');
		$parentIds = $parentId !== null ? [$parentId] : null;

		$limit = self::DEFAULT_LIMIT;

		if ($request->pagination !== null)
		{
			$limit = min($request->pagination->getLimit(), self::MAX_LIMIT);
		}

		$nodes = InternalContainer::getNodeRepository()->findAllByName(
			name: $name,
			parentIds: $parentIds,
			nodeTypes: [$type],
			limit: $limit,
			structureAction: StructureAction::ViewAction,
		);

		$collection = new DtoCollection(NodeDto::class);

		foreach ($nodes as $node)
		{
			$collection->add($this->mapNodeToDto($node));
		}

		return new ListResponse($collection);
	}

	/**
	 * Get total count of departments and teams.
	 *
	 * @restMethod humanresources.node.count
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\CompanyStructure\GetTotalNodeCountTool
	 */
	public function countAction(): ArrayResponse
	{
		$defaultStructure = StructureHelper::getDefaultStructure();
		if ($defaultStructure === null)
		{
			return new ArrayResponse([
				'departments' => 0,
				'teams' => 0,
			]);
		}

		$nodeRepository = InternalContainer::getNodeRepository();

		return new ArrayResponse([
			'departments' => $nodeRepository->countAll(
				structureId: $defaultStructure->id,
				structureAction: StructureAction::ViewAction,
			),
			'teams' => $nodeRepository->countAll(
				nodeTypes: [NodeEntityType::TEAM],
				structureId: $defaultStructure->id,
				structureAction: StructureAction::ViewAction,
			),
		]);
	}

	/**
	 * Get direct children of a node (departments and teams).
	 *
	 * @restMethod humanresources.node.children
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeGetChildrenTool
	 */
	public function childrenAction(GetRequest $request): ListResponse
	{
		$nodeId = (int)$request->id;

		$parentNode = $this->requireNodeById($nodeId);

		$this->checkNodeViewAccess($parentNode);

		$nodes = (new NodeDataBuilder())
			->addFilter(
				new NodeFilter(
					idFilter: IdFilter::fromId($parentNode->id),
					entityTypeFilter: NodeTypeFilter::fromNodeTypes([NodeEntityType::DEPARTMENT, NodeEntityType::TEAM]),
					structureId: $parentNode->structureId,
					direction: Direction::CHILD,
					depthLevel: DepthLevel::FIRST,
					active: true,
					accessFilter: new NodeAccessFilter(StructureAction::ViewAction, $this->userId),
				),
			)
			->setSort(new NodeSort(sort: SortDirection::Asc))
			->getAll()
		;

		$collection = new DtoCollection(NodeDto::class);
		foreach ($nodes as $node)
		{
			$collection->add($this->mapNodeToDto($node));
		}

		return new ListResponse($collection);
	}

	/**
	 * Create a new node (department or team).
	 *
	 * @restMethod humanresources.node.add
	 * @see \Bitrix\HumanResources\Controller\Structure\Node::addAction
	 * @see \Bitrix\HumanResources\Controller\Structure\Department::createAction
	 * @see \Bitrix\HumanResources\Controller\Structure\Team::createAction
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeCreateTool
	 *
	 * Request params:
	 * - string type              Node type: "DEPARTMENT" or "TEAM" (required)
	 * - string name              Node name (required)
	 * - int    parentId          Parent node identifier (required)
	 * - string description       Node description (optional)
	 * - string colorName         Color: blue, green, cyan, orange, purple, pink (optional)
	 * - object userIds           User IDs by role, e.g. {"MEMBER_HEAD": [1]} (optional)
	 * - bool   moveUsersToNode   Move users instead of adding (default: false)
	 * - bool   createChat        Create default chat (default: false)
	 * - int[]  bindingChatIds    Chat IDs to bind (optional)
	 * - bool   createChannel     Create default channel (default: false)
	 * - int[]  bindingChannelIds Channel IDs to bind (optional)
	 * - bool   createCollab      Create default collab (default: false)
	 * - int[]  bindingCollabIds  Collab IDs to bind (optional)
	 * - object settings          Node settings (optional)
	 */
	public function addAction(): ArrayResponse
	{
		$params = new RequestParams($this->getRequest()->getJsonList());

		$name = $params->requireString('name');
		$parentId = $params->requireInt('parentId');
		$type = $this->getNodeTypeFromRequest();
		$parentNode = $this->requireNodeById($parentId);

		$this->checkNodeCreateAccess($parentNode, $type);

		$structure = StructureHelper::getDefaultStructure();
		if ($structure === null)
		{
			throw new RequestValidationException([
				new Error('Default structure not found.', 'STRUCTURE_NOT_FOUND'),
			]);
		}

		$moveUsersToNode = $params->getBool('moveUsersToNode');
		$areCollabsAvailable = Feature::instance()->isCollabsAvailable();

		$command = new CreateNodeCommand(
			structureId: $structure->id,
			name: $name,
			entityType: $type,
			parentId: $parentId,
			description: $params->getString('description'),
			colorName: $params->getString('colorName'),
			usersStrategy: $moveUsersToNode
				? UserAddStrategy::MoveUsersStrategy
				: UserAddStrategy::SaveUsersStrategy,
			userIds: $params->getArray('userIds'),
			createChat: $params->getBool('createChat'),
			bindingChatIds: $params->getArray('bindingChatIds'),
			createChannel: $params->getBool('createChannel'),
			bindingChannelIds: $params->getArray('bindingChannelIds'),
			createCollab: $areCollabsAvailable && $params->getBool('createCollab'),
			bindingCollabIds: $areCollabsAvailable ? $params->getArray('bindingCollabIds') : [],
			settings: $params->getArray('settings'),
		);

		try
		{
			$commandResult = $command->run();
		}
		catch (CommandValidateException $e)
		{
			throw new RequestValidationException($e->getValidationErrors());
		}
		catch (CommandException $e)
		{
			throw new RequestValidationException([
				new Error($e->getMessage()),
			]);
		}

		if (!$commandResult->isSuccess())
		{
			throw new RequestValidationException($commandResult->getErrors());
		}

		$node = $commandResult->node;
		$dto = $this->mapNodeToDto($node);
		$dto->members = $this->getNodeMembers($node);

		return new ArrayResponse($dto->toArray());
	}

	/**
	 * Edit node properties (name, description, color).
	 *
	 * @restMethod humanresources.node.edit
	 * @see \Bitrix\HumanResources\Controller\Structure\Node::updateAction
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeEditTool
	 *
	 * Request params:
	 * - int    id          Node identifier (required)
	 * - string name        New name (optional)
	 * - string description New description (optional)
	 * - string colorName   New color (optional)
	 */
	public function editAction(): ArrayResponse
	{
		$params = new RequestParams($this->getRequest()->getJsonList());

		$nodeId = $params->requireInt('id');
		$node = $this->requireNodeById($nodeId);

		$this->checkNodeEditAccess($node);

		$params->requireAnyOf('name', 'description', 'colorName');

		$name = $params->getString('name');
		$description = $params->getString('description');
		$colorName = $params->getString('colorName');

		if ($name !== null)
		{
			$node->name = $name;
		}
		if ($description !== null)
		{
			$node->description = $description;
		}
		if ($colorName !== null)
		{
			$node->colorName = $colorName;
		}

		Container::getNodeService()->updateNode($node);

		$updatedNode = Container::getNodeRepository()->getById($nodeId);
		$dto = $this->mapNodeToDto($updatedNode);

		return new ArrayResponse($dto->toArray());
	}

	/**
	 * Move node to a new parent (change parent).
	 *
	 * @restMethod humanresources.node.move
	 * @see \Bitrix\HumanResources\Controller\Structure\Node::updateAction — parentId parameter
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeChangeParentTool
	 *
	 * Request params:
	 * - int id        Node identifier (required)
	 * - int parentId  New parent node identifier (required)
	 */
	public function moveAction(): ArrayResponse
	{
		$params = new RequestParams($this->getRequest()->getJsonList());

		$nodeId = $params->requireInt('id');
		$parentId = $params->requireInt('parentId');
		$node = $this->requireNodeById($nodeId);
		$parentNode = $this->requireNodeById($parentId);

		$this->checkNodeEditAccess($node, $parentId);

		$node->parentId = $parentId;
		Container::getNodeService()->updateNode($node);

		$updatedNode = Container::getNodeRepository()->getById($nodeId);
		$dto = $this->mapNodeToDto($updatedNode);

		return new ArrayResponse($dto->toArray());
	}

	private function mapNodeToDto(NodeItem $node): NodeDto
	{
		$info = StructureHelper::getNodeInfo($node);

		$dto = new NodeDto();
		$dto->id = $info['id'];
		$dto->name = $info['name'];
		$dto->type = $info['entityType'];
		$dto->structureId = $node->structureId;
		$dto->parentId = $info['parentId'];
		$dto->description = $info['description'];
		$dto->accessCode = $info['accessCode'];
		$dto->userCount = $info['userCount'];
		$dto->colorName = $info['colorName'] ?? null;
		$dto->xmlId = $node->xmlId;
		$dto->createdAt = $node->createdAt?->format(\DateTimeInterface::ATOM);
		$dto->updatedAt = $node->updatedAt?->format(\DateTimeInterface::ATOM);

		return $dto;
	}

	private function getNodeMembers(NodeItem $node): array
	{
		$employees = Container::getNodeMemberRepository()->findAllByNodeId($node->id);

		$roles = Container::getRoleRepository()->findByIds(
			array_map(fn($member) => $member->roles[0] ?? null, $employees->getValues()),
		);
		$rolesById = [];

		foreach ($roles->getValues() as $role)
		{
			$rolesById[$role->id] = $role->xmlId;
		}

		$userService = Container::getUserService();
		$userCollection = $userService->getUserCollectionFromMemberCollection($employees);

		$employeesByEntityId = [];
		foreach ($employees->getValues() as $emp)
		{
			$employeesByEntityId[$emp->entityId] = $emp;
		}

		$members = [];
		foreach ($userCollection as $user)
		{
			$employee = $employeesByEntityId[$user->id] ?? null;
			$info = $userService->getBaseInformation($user);
			$roleId = $employee?->roles[0] ?? null;

			$memberDto = new NodeMemberDto();
			$memberDto->userId = $info['id'];
			$memberDto->name = $info['name'];
			$memberDto->workPosition = $info['workPosition'];
			$memberDto->role = $roleId && isset($rolesById[$roleId]) ? $rolesById[$roleId] : null;
			$memberDto->avatar = $info['avatar'];
			$memberDto->url = $info['url'];

			$members[] = $memberDto->toArray();
		}

		return $members;
	}

	private function checkNodeEditAccess(NodeItem $node, ?int $targetNodeId = null): void
	{
		$actionId = $node->type === NodeEntityType::TEAM
			? StructureActionDictionary::ACTION_TEAM_EDIT
			: StructureActionDictionary::ACTION_DEPARTMENT_EDIT
		;

		$model = NodeModel::createFromNode($node);
		if ($targetNodeId !== null)
		{
			$model->setTargetNodeId($targetNodeId);
		}

		if (!$this->accessController->check($actionId, $model))
		{
			throw new AccessDeniedException();
		}
	}

	private function checkNodeCreateAccess(NodeItem $parentNode, NodeEntityType $type): void
	{
		$actionId = $type === NodeEntityType::TEAM
			? StructureActionDictionary::ACTION_TEAM_CREATE
			: StructureActionDictionary::ACTION_DEPARTMENT_CREATE
		;

		$parentModel = NodeModel::createFromNode($parentNode);
		$parentModel->setTargetNodeId($parentNode->id);

		if (!$this->accessController->check($actionId, $parentModel))
		{
			throw new AccessDeniedException();
		}
	}

	private function getNodeTypeFromRequest(): NodeEntityType
	{
		$params = new RequestParams($this->getRequest()->getJsonList());

		return $params->requireEnum('type', NodeEntityType::class);
	}
}
