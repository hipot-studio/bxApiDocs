<?php

namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Type\MemberEntityType;

class DepartmentMemberReader
{
	/**
	 * @param int[] $departmentIds
	 * @param bool $isFlat members of the departments themselves, without the nested ones
	 * @param int $limit counts rows: a person takes one per membership and per department it is read through
	 * @return int[] one per membership
	 */
	public function readUserIds(array $departmentIds, bool $isFlat, int $limit): array
	{
		return $this->createBuilder($departmentIds, $isFlat)
			->setLimit($limit)
			->getAll()
			->getEntityIds()
		;
	}

	/**
	 * @param int[] $departmentIds
	 * @param bool $isFlat members of the departments themselves, without the nested ones
	 * @param int $limit counts people
	 * @return int[] every person once
	 */
	public function readDistinctUserIds(array $departmentIds, bool $isFlat, int $limit): array
	{
		$rows = $this->createBuilder($departmentIds, $isFlat)
			->prepareQuery()
			->setSelect(['ENTITY_ID'])
			->setDistinct()
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['ENTITY_ID'], $rows);
	}

	/**
	 * @param int[] $departmentIds
	 */
	private function createBuilder(array $departmentIds, bool $isFlat): NodeMemberDataBuilder
	{
		return NodeMemberDataBuilder::createWithFilter(
			new NodeMemberFilter(
				entityType: MemberEntityType::USER,
				nodeFilter: new NodeFilter(
					idFilter: IdFilter::fromIds($departmentIds),
					depthLevel: $isFlat ? DepthLevel::NONE : DepthLevel::FULL,
				),
			),
		);
	}
}
