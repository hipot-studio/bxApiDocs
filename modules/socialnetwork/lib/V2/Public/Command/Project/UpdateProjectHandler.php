<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project;

use Bitrix\Main\Result;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\ProjectDates;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectInputNormalizer;
use Bitrix\Socialnetwork\V2\Internal\Service\UpdateProjectService;
use Bitrix\Socialnetwork\V2\Public\Mapper\ProjectAvatarMapper;
use Bitrix\Socialnetwork\V2\Public\Mapper\ProjectMemberMapper;

class UpdateProjectHandler
{
	public function __construct(
		private readonly UpdateProjectService $updateProjectService,
		private readonly ProjectInputNormalizer $normalizer,
		private readonly ProjectMemberMapper $projectMemberMapper,
		private readonly ProjectAvatarMapper $projectAvatarMapper,
	)
	{
	}

	public function __invoke(UpdateProjectCommand $command): Result
	{
		$dto = $command->input;
		$members = $this->projectMemberMapper->mapToEntityCollection($dto->members);
		$moderatorMembers = $this->projectMemberMapper->mapToEntityCollection($dto->moderatorMembers);
		[$members, $moderatorMembers] = $this->normalizer->normalizeUpdateMemberCollections(
			$members,
			$moderatorMembers,
		);
		[$dateStart, $dateFinish] = $this->normalizer->normalizeDateRange(
			$dto->dates?->start,
			$dto->dates?->finish,
		);

		$notifications = null;
		if ($dto->notifications !== null)
		{
			$notifications = [];
			foreach ($dto->notifications->types as $typeInput)
			{
				if ($typeInput->id !== null && $typeInput->counterEnabled !== null)
				{
					$notifications[] = [
						'id' => $typeInput->id,
						'counterEnabled' => $typeInput->counterEnabled,
					];
				}
			}
		}

		$project = new Project(
			id: $dto->id,
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
			members: $members,
			moderatorMembers: $moderatorMembers,
			rawPermissions: $this->normalizer->normalizePermissions($dto->permissions),
			options: $dto->options,
			features: $dto->features,
			baseFeatureId: $dto->baseFeatureId,
			tagNames: $dto->tags,
			publication: $dto->publication,
			notifications: $notifications,
		);

		return $this->updateProjectService->update(
			project: $project,
			userId: $command->userId,
			isCurrentUserModuleAdmin: $command->isCurrentUserModuleAdmin,
		);
	}
}
