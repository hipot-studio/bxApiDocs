<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\SelectionCondition\Node\NodeAccessFilter;
use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\HumanResources\Builder\Structure\Sort\NodeSort;
use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Enum\SortDirection;
use Bitrix\HumanResources\Item\Collection\NodeCollection;
use Bitrix\HumanResources\Model\NodeBackwardAccessCodeTable;
use Bitrix\HumanResources\Public\Service\NodeService;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureAction;

final class CompatNodeService extends NodeService
{
	private const DEPARTMENT_CODE = '/^DR?[0-9]+\z/';
	private const DEPARTMENT_CODES_PER_QUERY = 500;

	public function findAll(
		int $structureId,
		array $nodeTypes = [NodeEntityType::DEPARTMENT],
		?StructureAction $structureAction = null,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
		int $limit = 100,
		int $offset = 0,
		?int $viewerUserId = null,
	): NodeCollection
	{
		return (new NodeDataBuilder())
			->addFilter(
				new NodeFilter(
					entityTypeFilter: NodeTypeFilter::fromNodeTypes($nodeTypes),
					structureId: $structureId,
					active: self::activeFilter($activeFilter),
					accessFilter: self::accessFilter($structureAction, $viewerUserId),
				),
			)
			->setSort(new CompatNodeSort())
			->setLimit($limit)
			->setOffset($offset)
			->getAll()
		;
	}

	public function findAllByIds(
		array $nodeIds,
		?int $structureId = null,
		array $nodeTypes = [NodeEntityType::DEPARTMENT],
		?StructureAction $structureAction = null,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
		?int $viewerUserId = null,
	): NodeCollection
	{
		if (empty($nodeIds))
		{
			return new NodeCollection();
		}

		return (new NodeDataBuilder())
			->addFilter(
				new NodeFilter(
					idFilter: IdFilter::fromIds($nodeIds),
					entityTypeFilter: NodeTypeFilter::fromNodeTypes($nodeTypes),
					structureId: $structureId,
					active: self::activeFilter($activeFilter),
					accessFilter: self::accessFilter($structureAction, $viewerUserId),
				),
			)
			->setSort(new NodeSort(sort: SortDirection::Asc))
			->getAll()
		;
	}

	/**
	 * @param array<string> $accessCodes
	 *
	 * @return array<string, list<int>>
	 */
	public function getNodeIdsGroupedByAccessCode(array $accessCodes): array
	{
		$accessCodes = array_unique($accessCodes);
		$departmentCodes = array_filter(
			$accessCodes,
			static fn (string $accessCode): bool => preg_match(self::DEPARTMENT_CODE, $accessCode) === 1,
		);
		$byDepartmentCode = $this->findNodeIdsByDepartmentCodes($departmentCodes);

		$grouped = [];
		foreach ($accessCodes as $position => $accessCode)
		{
			$nodeIds = isset($departmentCodes[$position])
				? ($byDepartmentCode[$accessCode] ?? [])
				: $this->getNodeIdsByAccessCodes([$accessCode])
			;
			if ($nodeIds !== [])
			{
				$grouped[$accessCode] = array_values(array_unique(array_map('intval', $nodeIds)));
			}
		}

		return $grouped;
	}

	/**
	 * @param array<string> $departmentCodes
	 *
	 * @return array<string, list<int>>
	 */
	private function findNodeIdsByDepartmentCodes(array $departmentCodes): array
	{
		$askedUnder = [];
		foreach ($departmentCodes as $departmentCode)
		{
			$plainCode = str_starts_with($departmentCode, 'DR') ? 'D' . substr($departmentCode, 2) : $departmentCode;
			$askedUnder[$plainCode][] = $departmentCode;
		}

		$found = [];
		foreach (array_chunk(array_keys($askedUnder), self::DEPARTMENT_CODES_PER_QUERY) as $chunk)
		{
			$rows = NodeBackwardAccessCodeTable::query()
				->addSelect('NODE_ID')
				->addSelect('ACCESS_CODE')
				->whereIn('ACCESS_CODE', $chunk)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				foreach ($askedUnder[$row['ACCESS_CODE']] ?? [] as $departmentCode)
				{
					$found[$departmentCode][] = (int)$row['NODE_ID'];
				}
			}
		}

		return $found;
	}

	private static function activeFilter(NodeActiveFilter $activeFilter): bool|NodeActiveFilter
	{
		return $activeFilter === NodeActiveFilter::ONLY_GLOBAL_ACTIVE ? true : $activeFilter;
	}

	private static function accessFilter(?StructureAction $structureAction, ?int $viewerUserId): ?NodeAccessFilter
	{
		return $structureAction === null
			? null
			: new NodeAccessFilter($structureAction, userId: $viewerUserId)
		;
	}
}
