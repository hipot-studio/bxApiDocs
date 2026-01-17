<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\Kanban\StagesTable;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\StageMapper;

class StageRepository implements StageRepositoryInterface
{
	public function __construct(
		private readonly StageMapper $stageMapper,
	)
	{
	}

	public function getById(int $id): ?Entity\Stage
	{
		$select = [
			'ID',
			'TITLE',
			'COLOR',
			'SORT',
			'SYSTEM_TYPE',
		];

		$stage = StagesTable::getByPrimary($id, ['select' => $select])->fetch();
		if (!is_array($stage))
		{
			return null;
		}

		return $this->stageMapper->mapToEntity($stage);
	}

	public function getByGroupId(int $groupId): Entity\StageCollection
	{
		$workMode = StagesTable::getWorkMode();

		StagesTable::setWorkMode(StagesTable::WORK_MODE_GROUP);

		$stages = StagesTable::getStages($groupId);

		StagesTable::setWorkMode($workMode);

		return $this->stageMapper->mapToCollection($stages);
	}

	public function getFirstIdByGroupId(int $groupId): ?int
	{
		$stage = StagesTable::getList([
			'select' => ['ID'],
			'filter' => [
				'ENTITY_ID' => $groupId,
				'=ENTITY_TYPE' => StagesTable::WORK_MODE_GROUP,
			],
			'order' => [
				'SORT' => 'ASC',
			],
			'limit' => 1,
		])->fetch();

		return $stage ? (int)$stage['ID'] : null;
	}
}
