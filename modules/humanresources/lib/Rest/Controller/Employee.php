<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Rest\Controller;

use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Rest\Dto\EmployeeDto;
use Bitrix\HumanResources\Rest\RequestParams;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Util\StructureHelper;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

/**
 * Employee operations: search, subordinates, count, multi-department.
 *
 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member\Employee — existing ajax controller
 */
#[DtoType(EmployeeDto::class)]
class Employee extends RestController
{
	protected function processBeforeAction(\Bitrix\Main\Engine\Action $action): bool
	{
		$result = parent::processBeforeAction($action);
		if (!$result)
		{
			return false;
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0 || !Container::getUserService()->isEmployee($userId))
		{
			throw new AccessDeniedException();
		}

		return true;
	}

	/**
	 * Search employees by name, optionally within a specific node.
	 *
	 * @restMethod humanresources.employee.search
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\CompanyStructure\SearchEmployeeTool
	 *
	 * Request params:
	 * - string name    Search query (required)
	 * - int    nodeId  Filter by node (optional)
	 */
	public function searchAction(ListRequest $request): ListResponse
	{
		$params = new RequestParams($this->getRequest()->getJsonList());
		$name = $params->requireString('name');
		$nodeId = $params->getInt('nodeId');

		$currentUserId = (int)CurrentUser::get()->getId();

		$searchService = InternalContainer::getUserService();
		$allUsers = $searchService->searchByName($name, $nodeId);
		$nodesByUsers = $searchService->getNodesByUsers($allUsers, $currentUserId);

		$userService = Container::getUserService();
		$collection = new DtoCollection(EmployeeDto::class);

		foreach ($allUsers as $user)
		{
			$info = $userService->getBaseInformation($user);
			$nodes = $nodesByUsers[$user->id] ?? ['departments' => [], 'teams' => []];

			$dto = new EmployeeDto();
			$dto->userId = $info['id'];
			$dto->name = $info['name'];
			$dto->workPosition = $info['workPosition'];
			$dto->avatar = $info['avatar'];
			$dto->url = $info['url'];
			$dto->departments = $nodes['departments'];
			$dto->teams = $nodes['teams'];

			$collection->add($dto);
		}

		return new ListResponse($collection);
	}

	/**
	 * Get subordinates count by departments for a given user.
	 *
	 * @restMethod humanresources.employee.subordinates
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\GetSubordinatesCountTool
	 */
	public function subordinatesAction(GetRequest $request): ArrayResponse
	{
		$targetUserId = (int)$request->id;
		if ($targetUserId <= 0)
		{
			throw new RequestValidationException([
				new Error('Parameter "id" is required and must be a positive integer.', 'INVALID_ID'),
			]);
		}

		$currentUserId = (int)CurrentUser::get()->getId();
		$departments = InternalContainer::getNodeMemberService()->getSubordinatesCountByUser($targetUserId, $currentUserId);

		return new ArrayResponse([
			'userId' => $targetUserId,
			'departments' => $departments,
		]);
	}

	/**
	 * Get total employee count across the structure.
	 *
	 * @restMethod humanresources.employee.count
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\CompanyStructure\GetTotalEmployeeCountTool
	 */
	public function countAction(): ArrayResponse
	{
		$structure = StructureHelper::getDefaultStructure();
		if ($structure === null)
		{
			return new ArrayResponse(['total' => 0]);
		}

		$rootNode = InternalContainer::getNodeRepository()->getRootNodeByStructureId($structure->id);
		if ($rootNode === null)
		{
			return new ArrayResponse(['total' => 0]);
		}

		$count = InternalContainer::getNodeMemberRepository()->countUniqueUsersByNodeIdWithSubNodes($rootNode->id);

		return new ArrayResponse(['total' => $count]);
	}

	/**
	 * Get employees who belong to more than one department.
	 *
	 * @restMethod humanresources.employee.multidepartment
	 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member::getMultipleUsersMapAction
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\GetMultiDepartmentEmployeesTool
	 */
	public function multiDepartmentAction(): ArrayResponse
	{
		$multiDept = InternalContainer::getNodeMemberRepository()->getMultipleNodeMembers(
			NodeEntityType::DEPARTMENT,
		);

		if (empty($multiDept))
		{
			return new ArrayResponse(['employees' => [], 'total' => 0]);
		}

		$allNodeIds = array_unique(array_merge(...array_values($multiDept)));
		$nodeCollection = InternalContainer::getNodeRepository()->findAllByIds(
			nodeIds: $allNodeIds
		);
		$nodesById = [];
		foreach ($nodeCollection as $node)
		{
			$nodesById[$node->id] = $node;
		}

		$userService = InternalContainer::getUserService();
		$usersById = [];
		foreach (InternalContainer::getUserRepository()->getByIds(array_keys($multiDept)) as $user)
		{
			$usersById[$user->id] = $user;
		}

		$employees = [];
		foreach ($multiDept as $uid => $nodeIds)
		{
			$user = $usersById[$uid] ?? null;
			if (!$user)
			{
				continue;
			}

			$departments = [];
			foreach ($nodeIds as $nodeId)
			{
				$node = $nodesById[$nodeId] ?? null;
				if ($node !== null)
				{
					$departments[] = [
						'id' => $node->id,
						'name' => $node->name,
					];
				}
			}

			$employees[] = [
				'userId' => $user->id,
				'name' => $userService->getUserName($user),
				'workPosition' => $user->workPosition,
				'departments' => $departments,
			];
		}

		return new ArrayResponse([
			'employees' => $employees,
			'total' => count($employees),
		]);
	}
}
