<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project;

use Bitrix\Main\Result;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\ProjectDates;
use Bitrix\Socialnetwork\V2\Internal\Exceptions\ProjectNameExistsException;
use Bitrix\Socialnetwork\V2\Internal\Service\CopyProjectService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectInputNormalizer;
use Bitrix\Socialnetwork\V2\Public\Mapper\ProjectAvatarMapper;
use Bitrix\Socialnetwork\V2\Public\Mapper\ProjectMemberMapper;

class CopyProjectHandler
{
	public function __construct(
		private readonly CopyProjectService $copyProjectService,
		private readonly ProjectInputNormalizer $normalizer,
		private readonly ProjectMemberMapper $projectMemberMapper,
		private readonly ProjectAvatarMapper $projectAvatarMapper,
	)
	{
	}

	public function __invoke(CopyProjectCommand $command): Result
	{
		$dto = $command->project;
		$moderatorMembers = $this->projectMemberMapper->mapToEntityCollection($dto->moderatorMembers);
		$members = $this->normalizer->normalizeMembers(
			$this->projectMemberMapper->mapToEntityCollection($dto->members),
			$moderatorMembers,
		);
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
			members: $members,
			moderatorMembers: $moderatorMembers,
			rawPermissions: $this->normalizer->normalizePermissions($dto->permissions),
			options: $dto->options,
			tagNames: $dto->tags,
			publication: $dto->publication,
		);

		try
		{
			return $this->copyProjectService->copy(
				sourceProjectId: $command->sourceProjectId,
				project: $project,
				copyOptions: $command->copyOptions?->toArray(),
				userId: $command->userId,
				isCurrentUserModuleAdmin: $command->isCurrentUserModuleAdmin,
			);
		}
		catch (ProjectNameExistsException $exception)
		{
			return (new Result())->addError($exception->toError());
		}
	}
}
