<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\Kanban\StagesTable;
use Bitrix\Tasks\Scrum\Form\EntityForm;
use Bitrix\Tasks\Scrum\Internal\EntityTable;
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
			'ENTITY_ID',
			'ENTITY_TYPE',
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

	public function getSystemStageIdByGroupId(int $groupId): ?int
	{
		$systemStage = StagesTable::getSystemStage($groupId);

		return $systemStage?->getId();
	}

	public function isGroupStage(int $stageId, int $groupId): bool
	{
		if ($stageId <= 0 || $groupId <= 0)
		{
			return false;
		}

		$stage = StagesTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=ID' => $stageId,
				'ENTITY_ID' => $groupId,
				'=ENTITY_TYPE' => StagesTable::WORK_MODE_GROUP,
			],
			'limit' => 1,
		])->fetch();

		return is_array($stage);
	}

	public function isActiveSprintStage(int $stageId, int $groupId): bool
	{
		if ($stageId <= 0 || $groupId <= 0)
		{
			return false;
		}

		$sprintId = $this->getActiveSprintId($groupId);
		if ($sprintId === null)
		{
			return false;
		}

		$stage = StagesTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=ID' => $stageId,
				'ENTITY_ID' => $sprintId,
				'=ENTITY_TYPE' => StagesTable::WORK_MODE_ACTIVE_SPRINT,
			],
			'limit' => 1,
		])->fetch();

		return is_array($stage);
	}

	private function getActiveSprintId(int $groupId): ?int
	{
		$sprint = EntityTable::getList([
			'select' => ['ID'],
			'filter' => [
				'GROUP_ID' => $groupId,
				'=STATUS' => EntityForm::SPRINT_ACTIVE,
			],
			'limit' => 1,
		])->fetch();

		return is_array($sprint) ? (int)$sprint['ID'] : null;
	}
}
