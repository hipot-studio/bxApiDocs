<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project;

use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Collab\Registry\CollabRegistry;
use Bitrix\Socialnetwork\V2\Internal\Entity\PrivacyType;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectFeatureRepository;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\ProjectDates;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\FeatureDictionary;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectInputNormalizer;
use Bitrix\Socialnetwork\V2\Internal\Service\ProjectAnalytics;
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
		private readonly ProjectAnalytics $projectAnalytics,
		private readonly ProjectFeatureRepository $projectFeatureRepository,
		private readonly CollabRegistry $collabRegistry,
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

		$collab = $dto->id > 0 && $command->userId > 0 ? $this->collabRegistry->get($dto->id) : null;
		$wasListsActive = $collab !== null ? $this->getListsState($dto->id) : false;
		$wasOpened = $collab?->isOpened();

		$result = $this->updateProjectService->update(
			project: $project,
			userId: $command->userId,
			isCurrentUserModuleAdmin: $command->isCurrentUserModuleAdmin,
		);

		if ($result->isSuccess())
		{
			$this->trackMemberChanges($command->userId, $result);
		}

		if ($collab !== null)
		{
			// A failed save describes the attempted switch; success uses the persisted state.
			$requestedState = $dto->features[FeatureDictionary::GroupLists->value] ?? $wasListsActive;
			$isListsActive = $result->isSuccess()
				? $this->getListsState($dto->id)
				: (is_bool($requestedState) ? $requestedState : $wasListsActive);
			$isOpened = $result->isSuccess() && $dto->privacyType !== null
				? $dto->privacyType === PrivacyType::Open
				: $wasOpened;

			$this->projectAnalytics->trackEditSettings(
				$dto->id,
				$command->userId,
				$wasListsActive,
				$isListsActive,
				$isOpened,
				$result->isSuccess() ? null : (string)$result->getErrors()[0]->getCode(),
			);
		}

		return $result;
	}

	/**
	 * Emits member/department add-delete analytics for an explicit project edit. The membership delta is
	 * reported by UpdateProjectService::update; copy reuses that service directly (not this handler), so it is
	 * naturally excluded. One event per entity kind per operation, mirroring MemberService; $userId is the
	 * initiator (used for p2 user type). The reentrant chat sync reaches MemberService with an already-synced
	 * member set and is a no-op, so there is no double counting.
	 */
	private function trackMemberChanges(int $userId, Result $result): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$data = $result->getData();
		$projectId = (int)($data['projectId'] ?? 0);
		$added = $data['addedMembers'] ?? null;
		$removed = $data['removedMembers'] ?? null;
		if (
			$projectId <= 0
			|| !$added instanceof MemberEntityCollection
			|| !$removed instanceof MemberEntityCollection
		)
		{
			return;
		}

		if (!$added->filterByType(MemberEntityType::User)->isEmpty())
		{
			$this->projectAnalytics->trackAddProjectUser($projectId, $userId, ProjectAnalytics::ORIGIN_PROJECT);
		}

		if (!$added->filterByType(MemberEntityType::Department)->isEmpty())
		{
			$this->projectAnalytics->trackAddDepartmentToProject($projectId, $userId, ProjectAnalytics::ORIGIN_PROJECT);
		}

		if (!$removed->filterByType(MemberEntityType::User)->isEmpty())
		{
			$this->projectAnalytics->trackDeleteProjectUser($projectId, $userId, ProjectAnalytics::ORIGIN_PROJECT);
		}

		if (!$removed->filterByType(MemberEntityType::Department)->isEmpty())
		{
			$this->projectAnalytics->trackDeleteDepartmentFromProject($projectId, $userId, ProjectAnalytics::ORIGIN_PROJECT);
		}
	}

	private function getListsState(int $projectId): bool
	{
		$states = $this->projectFeatureRepository->getFeatureStates($projectId);

		return ($states[FeatureDictionary::GroupLists->value] ?? false) === true;
	}
}
