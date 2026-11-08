<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Provider;

use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectRepositoryInterface;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\ProjectViewInfoResponse;

class ProjectViewInfoProvider
{
	private readonly ProjectRepositoryInterface $projectRepository;
	private readonly ProjectAccessProvider $accessProvider;

	public function __construct(
		?ProjectRepositoryInterface $projectRepository = null,
		?ProjectAccessProvider $accessProvider = null,
	)
	{
		$this->projectRepository = $projectRepository ?? Container::getInstance()->getProjectRepository();
		$this->accessProvider = $accessProvider ?? new ProjectAccessProvider();
	}

	public function getViewInfoById(int $projectId): ?ProjectViewInfoResponse
	{
		$project = $this->projectRepository->getViewInfoById($projectId);
		if ($project === null)
		{
			return null;
		}

		return $this->mapToResponse($project);
	}

	/**
	 * Returns the view info snapshot only for a user allowed to view the project, null otherwise.
	 *
	 * The row is read once and the permission is decided by that very row, so a caller with no
	 * declarative permission check of its own needs no separate read to guard this one.
	 */
	public function getViewInfoWithAccessCheck(
		int $projectId,
		int $userId,
		bool $checkAdminSession = true,
	): ?ProjectViewInfoResponse
	{
		$viewInfoRow = $this->projectRepository->findViewInfoRow($projectId);
		if ($viewInfoRow === null)
		{
			return null;
		}

		if (!$this->accessProvider->canViewProject($viewInfoRow->visibility(), $userId, $checkAdminSession))
		{
			return null;
		}

		return $this->mapToResponse($this->projectRepository->mapViewInfoRow($viewInfoRow));
	}

	private function mapToResponse(Project $project): ProjectViewInfoResponse
	{
		return new ProjectViewInfoResponse(
			description: $project->description ?? '',
			goal: $project->goal ?? '',
			dateStart: $this->formatCalendarDate($project->dates?->start),
			dateFinish: $this->formatCalendarDate($project->dates?->finish),
			tags: $project->tags?->getNameList() ?? [],
		);
	}

	private function formatCalendarDate(?DateTime $date): ?string
	{
		if ($date === null)
		{
			return null;
		}

		$userDate = clone $date;
		$userDate->toUserTime();

		return $userDate->format('Y-m-d');
	}
}
