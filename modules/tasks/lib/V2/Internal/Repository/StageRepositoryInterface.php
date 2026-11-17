<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity;

interface StageRepositoryInterface
{
	public function getByGroupId(int $groupId): Entity\StageCollection;

	public function getById(int $id): ?Entity\Stage;

	public function getFirstIdByGroupId(int $groupId): ?int;

	/**
	 * Reads the NEW system stage of the group kanban. Unlike {@see self::getByGroupId()}
	 * it never creates the group stages.
	 */
	public function getSystemStageIdByGroupId(int $groupId): ?int;

	/**
	 * Checks that the stage is a column of the given group kanban: ENTITY_ID alone is
	 * ambiguous, a personal kanban stage may carry the same value.
	 */
	public function isGroupStage(int $stageId, int $groupId): bool;

	/**
	 * Checks that the stage is a column of the active sprint of the given scrum group.
	 */
	public function isActiveSprintStage(int $stageId, int $groupId): bool;
}
