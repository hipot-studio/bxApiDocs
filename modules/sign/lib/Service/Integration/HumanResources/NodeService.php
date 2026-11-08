<?php

namespace Bitrix\Sign\Service\Integration\HumanResources;

use Bitrix\HumanResources\Config\Storage;
use Bitrix\HumanResources\Contract\Service\NodeMemberService as NodeMemberServiceContract;
use Bitrix\HumanResources\Contract\Service\NodeService as NodeServiceContract;
use Bitrix\HumanResources\Item\Collection\NodeMemberCollection;
use Bitrix\HumanResources\Model\NodeMemberTable;
use Bitrix\HumanResources\Model\NodePathTable;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\UserTable;
use Bitrix\Sign\Item\Hr\EntitySelector\Entity;
use Bitrix\Sign\Type\Hr\EntitySelector\EntityType;

final class NodeService
{
	private const MAX_SOURCE_USER_COUNT = 1000;

	private ?NodeServiceContract $nodeService = null;
	private ?NodeMemberServiceContract $nodeMemberService = null;
	/** @var null|\Closure(list<int>, list<int>, int, list<int>): list<int> */
	private readonly ?\Closure $employeeUserIdsReader;

	/**
	 * @param null|\Closure(list<int>, list<int>, int, list<int>): list<int> $employeeUserIdsReader
	 */
	public function __construct(?\Closure $employeeUserIdsReader = null)
	{
		$this->employeeUserIdsReader = $employeeUserIdsReader;

		if (!$this->isAvailable())
		{
			return;
		}

		$this->nodeService = Container::instance()->getNodeService();
		$this->nodeMemberService = Container::instance()->getNodeMemberService();
	}

	private function isAvailable(): bool
	{
		return Loader::includeModule('humanresources');
	}

	public function isNodeExists(int $nodeId): bool
	{
		if (!$this->isAvailable())
		{
			return false;
		}

		if ($nodeId < 1)
		{
			return false;
		}

		return $this->nodeService?->getNodeInformation($nodeId) !== null;
	}

	public function getAllEmployeesByEntitySelector(Entity $entity): ?NodeMemberCollection
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		if ($entity->entityId < 1)
		{
			return null;
		}

		return $this->nodeMemberService?->getAllEmployees(
			$entity->entityId,
			$entity->entityType !== EntityType::FlatDepartment,
		);
	}

	/**
	 * @param list<int> $excludedUserIds
	 *
	 * @return list<int>
	 */
	public function listEmployeeUserIds(
		Entity $entity,
		int $limit = self::MAX_SOURCE_USER_COUNT + 1,
		array $excludedUserIds = [],
	): array
	{
		return $this->listEmployeeUserIdsByEntities([$entity], $limit, $excludedUserIds);
	}

	/**
	 * @param list<Entity> $entities
	 * @param list<int> $excludedUserIds
	 *
	 * @return list<int>
	 */
	public function listEmployeeUserIdsByEntities(
		array $entities,
		int $limit = self::MAX_SOURCE_USER_COUNT + 1,
		array $excludedUserIds = [],
	): array
	{
		$limit = min(max(0, $limit), self::MAX_SOURCE_USER_COUNT + 1);
		if ($limit === 0 || !$this->isAvailable())
		{
			return [];
		}

		$recursiveNodeIds = [];
		$flatNodeIds = [];
		foreach ($entities as $entity)
		{
			if ($entity->entityId < 1 || !$entity->entityType->isDepartment())
			{
				continue;
			}

			if ($entity->entityType === EntityType::Department)
			{
				$recursiveNodeIds[$entity->entityId] = true;
			}
			else
			{
				$flatNodeIds[$entity->entityId] = true;
			}
		}

		if ($recursiveNodeIds === [] && $flatNodeIds === [])
		{
			return [];
		}

		$excludedUserIds = $this->normalizeUserIds($excludedUserIds);
		if ($this->employeeUserIdsReader !== null)
		{
			$userIds = ($this->employeeUserIdsReader)(
				array_keys($recursiveNodeIds),
				array_keys($flatNodeIds),
				$limit,
				$excludedUserIds,
			);
		}
		else
		{
			$userIds = $this->readEmployeeUserIdsByNodeIds(
				array_keys($recursiveNodeIds),
				array_keys($flatNodeIds),
				$limit,
				$excludedUserIds,
			);
		}

		return $this->normalizeLimitedUserIds($userIds, $limit, $excludedUserIds);
	}

	/**
	 * @param list<int> $recursiveNodeIds
	 * @param list<int> $flatNodeIds
	 * @param list<int> $excludedUserIds
	 *
	 * @return list<int>
	 */
	private function readEmployeeUserIdsByNodeIds(
		array $recursiveNodeIds,
		array $flatNodeIds,
		int $limit,
		array $excludedUserIds,
	): array
	{
		$userIds = [];
		if ($recursiveNodeIds !== [])
		{
			$userIds = $this->readEmployeeUserIdsByNodeIdList(
				$recursiveNodeIds,
				true,
				$limit,
				$excludedUserIds,
			);
		}

		$remainingLimit = $limit - count($userIds);
		if ($flatNodeIds === [] || $remainingLimit < 1)
		{
			return $userIds;
		}

		$flatUserIds = $this->readEmployeeUserIdsByNodeIdList(
			$flatNodeIds,
			false,
			$remainingLimit,
			[...$excludedUserIds, ...$userIds],
		);

		return [...$userIds, ...$flatUserIds];
	}

	/**
	 * @param list<int> $nodeIds
	 * @param list<int> $excludedUserIds
	 *
	 * @return list<int>
	 */
	private function readEmployeeUserIdsByNodeIdList(
		array $nodeIds,
		bool $recursive,
		int $limit,
		array $excludedUserIds,
	): array
	{
		$intranetUserQuery = UserTable::query()
			->setSelect(['ID'])
			->where('ACTIVE', 'Y')
			->where('IS_REAL_USER', 'Y')
			->where('UF_DEPARTMENT', '!=', false)
		;
		$query = NodeMemberTable::query()
			->setSelect(['ENTITY_ID'])
			->setDistinct()
			->where('ACTIVE', 'Y')
			->where('ENTITY_TYPE', MemberEntityType::USER->value)
			->where('NODE.TYPE', NodeEntityType::DEPARTMENT->value)
			->whereIn('ENTITY_ID', $intranetUserQuery)
			->setLimit($limit)
		;

		if ($recursive)
		{
			$query
				->registerRuntimeField(
					new Reference(
						'SOURCE_NODE_PATH',
						NodePathTable::class,
						Join::on('this.NODE_ID', 'ref.CHILD_ID'),
					),
				)
				->whereIn('SOURCE_NODE_PATH.PARENT_ID', $nodeIds)
			;
		}
		else
		{
			$query->whereIn('NODE_ID', $nodeIds);
		}

		if ($excludedUserIds !== [])
		{
			$query->whereNotIn('ENTITY_ID', $excludedUserIds);
		}

		$rows = $query->exec();
		$userIds = [];
		while ($row = $rows->fetch())
		{
			$userIds[] = (int)$row['ENTITY_ID'];
		}

		return $userIds;
	}

	/**
	 * @param array<mixed> $userIds
	 * @param list<int> $excludedUserIds
	 *
	 * @return list<int>
	 */
	private function normalizeLimitedUserIds(array $userIds, int $limit, array $excludedUserIds): array
	{
		$result = [];
		$excludedUserIdMap = array_fill_keys($excludedUserIds, true);
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId < 1 || isset($excludedUserIdMap[$userId]) || isset($result[$userId]))
			{
				continue;
			}

			$result[$userId] = true;
			if (count($result) === $limit)
			{
				break;
			}
		}

		return array_keys($result);
	}

	/**
	 * @param list<int> $userIds
	 *
	 * @return list<int>
	 */
	private function normalizeUserIds(array $userIds): array
	{
		$result = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$result[$userId] = true;
			}
		}

		return array_keys($result);
	}

	public function isCompanyStructureConverted(): bool
	{
		if (!$this->isAvailable())
		{
			return false;
		}

		return Storage::instance()->isCompanyStructureConverted();
	}
}
