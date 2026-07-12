<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\V2\Internal\Integration\Disk\Service\ProjectCopyService as DiskProjectCopyService;
use Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Service\ProjectCopyService as TasksProjectCopyService;

class ProjectCopyFeatureService
{
	public function __construct(
		private readonly TasksProjectCopyService $tasksProjectCopyService,
		private readonly DiskProjectCopyService $diskProjectCopyService,
	)
	{
	}

	public function copy(
		int $sourceProjectId,
		int $targetProjectId,
		?array $projectTerm,
		?array $copyOptions,
		int $userId,
	): Result
	{
		$result = $this->copyTasksFeature(
			sourceProjectId: $sourceProjectId,
			targetProjectId: $targetProjectId,
			userId: $userId,
			copyOptions: $copyOptions,
			projectTerm: $projectTerm,
		);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return $this->copyDiskFeature(
			sourceProjectId: $sourceProjectId,
			targetProjectId: $targetProjectId,
			userId: $userId,
			copyOptions: $copyOptions,
		);
	}

	private function copyTasksFeature(
		int $sourceProjectId,
		int $targetProjectId,
		int $userId,
		?array $copyOptions,
		?array $projectTerm,
	): Result
	{
		if (($copyOptions['tasks']['enabled'] ?? false) !== true)
		{
			return new Result();
		}

		try
		{
			$this->tasksProjectCopyService->copy(
				userId: $userId,
				sourceProjectId: $sourceProjectId,
				targetProjectId: $targetProjectId,
				features: $this->buildTasksFeatureFlags($copyOptions),
				datesPayload: $this->buildTasksDatesPayload($projectTerm),
			);

			return new Result();
		}
		catch (\Throwable $exception)
		{
			return $this->createFeatureError('tasks', $exception);
		}
	}

	private function copyDiskFeature(
		int $sourceProjectId,
		int $targetProjectId,
		int $userId,
		?array $copyOptions,
	): Result
	{
		if (($copyOptions['disk']['enabled'] ?? false) !== true)
		{
			return new Result();
		}

		try
		{
			$this->diskProjectCopyService->copy(
				userId: $userId,
				sourceProjectId: $sourceProjectId,
				targetProjectId: $targetProjectId,
				features: $this->buildDiskFeatureFlags($copyOptions),
			);

			return new Result();
		}
		catch (\Throwable $exception)
		{
			return $this->createFeatureError('disk', $exception);
		}
	}

	private function createFeatureError(string $featureName, \Throwable $exception): Result
	{
		return (new Result())->addError(new Error(
			sprintf('Failed to start %s project copy', $featureName),
			strtoupper($featureName) . '_PROJECT_COPY_FAILED',
			['feature' => $featureName],
		));
	}

	private function buildTasksFeatureFlags(?array $copyOptions): array
	{
		return ($copyOptions['tasks']['robots'] ?? false) === true
			? ['robots']
			: [];
	}

	private function buildTasksDatesPayload(?array $projectTerm): array
	{
		// A project copy must always carry the project=true flag, otherwise the tasks
		// copier falls into the unguarded getGroupDeadline() branch and fatals on a
		// project without dates. With project=true the deadline recount goes through
		// the date-safe project branch.
		return [
			'project' => true,
			'old_start_point' => $projectTerm['old_start_point'] ?? '',
			'start_point' => $projectTerm['start_point'] ?? '',
			'end_point' => $projectTerm['end_point'] ?? '',
		];
	}

	private function buildDiskFeatureFlags(?array $copyOptions): array
	{
		return ($copyOptions['disk']['withFiles'] ?? true) === false
			? ['onlyFolders']
			: [];
	}
}
