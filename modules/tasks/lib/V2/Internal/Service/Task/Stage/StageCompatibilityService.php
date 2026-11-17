<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Stage;

use Bitrix\Tasks\Control\Exception\TaskStageValidationException;
use Bitrix\Tasks\Kanban\StagesTable;
use Bitrix\Tasks\Scrum\Form\EntityForm;
use Bitrix\Tasks\Scrum\Service\EntityService;
use Bitrix\Tasks\V2\Internal\Entity\Stage;
use Bitrix\Tasks\V2\Internal\Repository\StageRepositoryInterface;

class StageCompatibilityService
{
	private int $validationScopeDepth = 0;

	/** @var array<string, true> */
	private array $validationCache = [];

	public function __construct(
		private readonly StageRepositoryInterface $stageRepository,
		private readonly EntityService $entityService,
	)
	{
	}

	public function normalizeStageId(mixed $stageId): ?int
	{
		if ($stageId === null || $stageId === false || $stageId === '')
		{
			return null;
		}

		if (is_int($stageId))
		{
			if ($stageId >= 0)
			{
				return $stageId;
			}

			$this->throwNotFound();
		}

		if (is_float($stageId))
		{
			if (!is_finite($stageId) || $stageId < 0 || floor($stageId) !== $stageId)
			{
				$this->throwNotFound();
			}

			$stageId = sprintf('%.0f', $stageId);
		}

		if (!is_string($stageId) || preg_match('/^\d+$/D', $stageId) !== 1)
		{
			$this->throwNotFound();
		}

		$normalized = ltrim($stageId, '0');
		if ($normalized === '')
		{
			return 0;
		}

		$max = (string)PHP_INT_MAX;
		if (
			strlen($normalized) > strlen($max)
			|| (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)
		)
		{
			$this->throwNotFound();
		}

		return (int)$normalized;
	}

	public function runWithValidationCache(callable $callback): mixed
	{
		$isOuterScope = $this->validationScopeDepth === 0;
		$this->validationScopeDepth++;

		try
		{
			return $callback();
		}
		finally
		{
			$this->validationScopeDepth--;

			if ($isOuterScope)
			{
				$this->validationCache = [];
			}
		}
	}

	public function validateStageId(
		int $stageId,
		int $targetGroupId,
		?Stage $stage = null,
	): void
	{
		if ($stageId === 0)
		{
			return;
		}

		if ($stage === null && isset($this->validationCache[$this->getValidationCacheKey($stageId, $targetGroupId)]))
		{
			return;
		}

		$stage ??= $this->stageRepository->getById($stageId);
		if ($stage === null || $stage->id !== $stageId)
		{
			$this->throwNotFound();
		}

		if ($targetGroupId <= 0)
		{
			$this->throwDifferentStages();
		}

		if ($stage->entityType === StagesTable::WORK_MODE_GROUP)
		{
			if ($stage->entityId !== $targetGroupId)
			{
				$this->throwDifferentStages();
			}

			$this->rememberValidation($stageId, $targetGroupId);

			return;
		}

		if (
			$stage->entityType === StagesTable::WORK_MODE_ACTIVE_SPRINT
			&& $this->isSprintEntityOfGroup((int)$stage->entityId, $targetGroupId)
		)
		{
			$this->rememberValidation($stageId, $targetGroupId);

			return;
		}

		$this->throwDifferentStages();
	}

	private function rememberValidation(int $stageId, int $targetGroupId): void
	{
		if ($this->validationScopeDepth > 0)
		{
			$this->validationCache[$this->getValidationCacheKey($stageId, $targetGroupId)] = true;
		}
	}

	private function getValidationCacheKey(int $stageId, int $targetGroupId): string
	{
		return $stageId . ':' . $targetGroupId;
	}

	private function isSprintEntityOfGroup(int $sprintId, int $groupId): bool
	{
		if ($sprintId <= 0 || $groupId <= 0)
		{
			return false;
		}

		$identity = $this->entityService->getEntityGroupAndType($sprintId);
		if ($identity === null)
		{
			return false;
		}

		return
			$identity['GROUP_ID'] === $groupId
			&& $identity['ENTITY_TYPE'] === EntityForm::SPRINT_TYPE
		;
	}

	public function throwNotFound(): never
	{
		\Bitrix\Main\Localization\Loc::loadMessages(__FILE__);

		throw new TaskStageValidationException(
			\Bitrix\Main\Localization\Loc::getMessage('TASKS_STAGE_NOT_FOUND') ?? 'Stage was not found',
			TaskStageValidationException::ERROR_NOT_FOUND,
		);
	}

	private function throwDifferentStages(): never
	{
		\Bitrix\Main\Localization\Loc::loadMessages(__FILE__);

		throw new TaskStageValidationException(
			\Bitrix\Main\Localization\Loc::getMessage('TASKS_STAGE_DIFFERENT_STAGES')
				?? 'Stage and task belong to different groups',
			TaskStageValidationException::ERROR_DIFFERENT_STAGES,
		);
	}
}
