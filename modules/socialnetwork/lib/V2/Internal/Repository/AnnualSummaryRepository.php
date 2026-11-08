<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Date;
use Bitrix\Socialnetwork\Collab\Internals\CollabOptionTable;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertStatus;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\ConvertProgressMapper;
use Bitrix\Socialnetwork\WorkgroupTable;

class AnnualSummaryRepository
{
	private const CONVERT_OPTION_REFERENCE = 'CONVERT_OPTION';
	private const CONTAINER_COUNT = 'CONTAINER_COUNT';

	/**
	 * @param int[] $ownerIds
	 * @return array<int, int>
	 */
	public function countLegacyContainers(array $ownerIds, Date $from, Date $to): array
	{
		$ownerIds = $this->normalizeOwnerIds($ownerIds);
		if ($ownerIds === [])
		{
			return [];
		}

		$query = $this->buildBaseQuery($ownerIds, $from, $to);
		$query->where(
			Query::filter()
				->logic('or')
				->whereNull(self::CONVERT_OPTION_REFERENCE . '.VALUE')
				->whereNot(self::CONVERT_OPTION_REFERENCE . '.VALUE', ConvertStatus::NotRequired->value)
		);

		return $this->fetchCounts($query);
	}

	/**
	 * @param int[] $ownerIds
	 * @return array<int, int>
	 */
	public function countNewProjects(array $ownerIds, Date $from, Date $to): array
	{
		$ownerIds = $this->normalizeOwnerIds($ownerIds);
		if ($ownerIds === [])
		{
			return [];
		}

		$query = $this->buildBaseQuery($ownerIds, $from, $to);
		$query->where(self::CONVERT_OPTION_REFERENCE . '.VALUE', ConvertStatus::NotRequired->value);

		return $this->fetchCounts($query);
	}

	/**
	 * @param int[] $ownerIds
	 */
	private function buildBaseQuery(array $ownerIds, Date $from, Date $to): Query
	{
		$query = WorkgroupTable::query()
			->whereIn('OWNER_ID', $ownerIds)
			->where('DATE_CREATE', '>=', $from)
			->where('DATE_CREATE', '<', $to)
			->where($this->buildNotScrumFilter())
			->setGroup(['OWNER_ID'])
		;

		$query->registerRuntimeField(
			(new Reference(
				name: self::CONVERT_OPTION_REFERENCE,
				referenceEntity: CollabOptionTable::class,
				referenceFilter: Join::on('this.ID', 'ref.COLLAB_ID')
					->where('ref.NAME', ConvertProgressMapper::CONVERT_STATUS),
			))->configureJoinType(Join::TYPE_LEFT),
		);
		$query->registerRuntimeField(
			new ExpressionField(self::CONTAINER_COUNT, 'COUNT(%s)', ['ID']),
		);
		$query->setSelect(['OWNER_ID', self::CONTAINER_COUNT]);

		return $query;
	}

	private function buildNotScrumFilter(): ConditionTree
	{
		return Query::filter()
			->logic('or')
			->whereNot('TYPE', Type::Scrum->value)
			->where(
				Query::filter()
					->whereNull('TYPE')
					->where(
						Query::filter()
							->logic('or')
							->whereNull('SCRUM_MASTER_ID')
							->where('SCRUM_MASTER_ID', '<=', 0)
					)
			)
		;
	}

	/**
	 * @return array<int, int>
	 */
	private function fetchCounts(Query $query): array
	{
		$result = [];
		$queryResult = $query->exec();
		while ($row = $queryResult->fetch())
		{
			$result[(int)$row['OWNER_ID']] = (int)$row[self::CONTAINER_COUNT];
		}

		return $result;
	}

	/**
	 * @param mixed[] $ownerIds
	 * @return int[]
	 */
	private function normalizeOwnerIds(array $ownerIds): array
	{
		$ownerIds = array_map('intval', $ownerIds);
		$ownerIds = array_filter($ownerIds, static fn(int $ownerId): bool => $ownerId > 0);

		return array_values(array_unique($ownerIds));
	}
}
