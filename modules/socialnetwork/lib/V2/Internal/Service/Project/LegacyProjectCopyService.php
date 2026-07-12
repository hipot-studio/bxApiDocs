<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Copy\GroupManager;
use Bitrix\Socialnetwork\V2\Internal\Entity\PrivacyType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectRepositoryInterface;

class LegacyProjectCopyService
{
	public function __construct(
		private readonly ProjectRepositoryInterface $projectRepository,
		private readonly ProjectCopyDatesBuilder $projectCopyDatesBuilder,
	)
	{
	}

	public function copy(
		int $sourceProjectId,
		Project $project,
		int $userId,
	): Result
	{
		$siteIds = $this->projectRepository->getSiteIds($sourceProjectId);
		if ($siteIds === [])
		{
			return (new Result())->addError(new Error('Failed to retrieve source project site ids'));
		}

		$copyManager = new GroupManager($userId, [$sourceProjectId]);
		$copyManager->setMarkerUsers(false);
		// Departments are linked through V2 NodeRelation in CopyProjectService::copy(),
		// the legacy UF_SG_DEPT channel must stay clean to avoid a duplicate sync source.
		$copyManager->setUfIgnoreList(['UF_SG_DEPT']);
		$copyManager->setChangedFields($this->buildChangedFields(
			project: $project,
			siteIds: $siteIds,
		));

		$datesPayload = $this->projectCopyDatesBuilder->build($project);
		if ($datesPayload !== null)
		{
			$copyManager->setProjectTerm($datesPayload);
		}

		$result = $copyManager->startCopy();
		if (!$result->isSuccess())
		{
			return $result;
		}

		$projectId = (int)($copyManager->getMapIdsCopiedGroups()[$sourceProjectId] ?? 0);
		if ($projectId <= 0)
		{
			return (new Result())->addError(new Error('Failed to retrieve copied project ID'));
		}

		return (new Result())->setData([
			'projectId' => $projectId,
			'projectTerm' => $copyManager->getProjectTerm(),
		]);
	}

	private function buildChangedFields(Project $project, array $siteIds): array
	{
		$fields = [
			'SITE_ID' => $siteIds,
			'IS_EXTRANET_GROUP' => 'N',
			'LANDING' => 'N',
			'MODERATORS' => [],
		];

		if ($project->name !== null)
		{
			$fields['NAME'] = $project->name;
		}

		if ($project->description !== null)
		{
			$fields['DESCRIPTION'] = $project->description;
		}

		if ($project->ownerId !== null)
		{
			$fields['OWNER_ID'] = $project->ownerId;
		}

		if ($project->privacyType !== null)
		{
			$isOpen = $project->privacyType === PrivacyType::Open;
			$fields['VISIBLE'] = $isOpen ? 'Y' : 'N';
			$fields['OPENED'] = $isOpen ? 'Y' : 'N';
		}

		return $fields;
	}
}
