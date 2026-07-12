<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project;

use Bitrix\Main\EventManager;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\ProjectDates;
use Bitrix\Socialnetwork\V2\Internal\Service\AddProjectService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectInputNormalizer;
use Bitrix\Socialnetwork\V2\Public\Event\Project\OnProjectCreated;
use Bitrix\Socialnetwork\V2\Public\Mapper\ProjectAvatarMapper;
use Bitrix\Socialnetwork\V2\Public\Mapper\ProjectMemberMapper;

class AddProjectHandler
{
	public function __construct(
		private readonly AddProjectService $addProjectService,
		private readonly ProjectInputNormalizer $normalizer,
		private readonly ProjectMemberMapper $projectMemberMapper,
		private readonly ProjectAvatarMapper $projectAvatarMapper,
	)
	{
	}

	public function __invoke(AddProjectCommand $command): Result
	{
		$dto = $command->input;
		$moderatorMembers = $this->projectMemberMapper->mapToEntityCollection($dto->moderatorMembers);
		[$dateStart, $dateFinish] = $this->normalizer->normalizeDateRange(
			$dto->dates?->start,
			$dto->dates?->finish,
		);

		$project = new Project(
			name: $dto->name,
			description: $dto->description,
			goal: $dto->goal,
			avatar: $this->projectAvatarMapper->toEntity($dto->avatar),
			ownerId: $dto->ownerId,
			privacyType: $dto->privacyType,
			dates: $dto->dates === null
				? null
				: new ProjectDates(
					start: $dateStart,
					finish: $dateFinish,
				),
			members: $this->normalizer->normalizeMembers(
				$this->projectMemberMapper->mapToEntityCollection($dto->members),
				$moderatorMembers,
			),
			moderatorMembers: $moderatorMembers,
			rawPermissions: $this->normalizer->normalizePermissions($dto->permissions),
			options: $dto->options,
			baseFeatureId: $dto->baseFeatureId,
			tagNames: $dto->tags,
			publication: $dto->publication,
		);

		$result =  $this->addProjectService->add(
			project: $project,
			userId: $command->userId,
			isCurrentUserModuleAdmin: $command->isCurrentUserModuleAdmin,
			enforceInitiatorMembership: $command->enforceInitiatorMembership,
		);

		if ($result->isSuccess())
		{
			$data = $result->getData();
			$projectId = $data['projectId'] ?? null;

			if ($projectId !== null)
			{
				EventManager::getInstance()->send(
					new OnProjectCreated($projectId, $project->ownerId ?? $command->userId),
				);
			}
		}

		return $result;
	}
}
