<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service;

use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Socialnetwork\Collab\Control\CollabResult;
use Bitrix\Socialnetwork\Collab\Control\CollabService;
use Bitrix\Socialnetwork\Collab\Control\Command\CollabUpdateCommand;
use Bitrix\Socialnetwork\Collab\Control\Option\OptionFactory;
use Bitrix\Socialnetwork\Control\Enum\ViewMode;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\ConvertChatService;
use Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Service\StructureRelationService;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectTagRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;
use Bitrix\Socialnetwork\V2\Internal\Entity\PrivacyType;
use Bitrix\Socialnetwork\V2\Internal\Service\Notification\ProjectNotificationSettingsService;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectAvatarLegacyPayloadBuilder;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\InitiatorMemberTrait;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectFeatureToggleService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectMemberDiffBuilder;

class UpdateProjectService
{
	use InitiatorMemberTrait;

	public function __construct(
		private readonly CollabService $collabService,
		private readonly WorkgroupFieldService $workgroupFieldService,
		private readonly ProjectTagRepositoryInterface $projectTagRepository,
		private readonly ProjectAvatarLegacyPayloadBuilder $projectAvatarLegacyPayloadBuilder,
		private readonly ProjectMemberDiffBuilder $projectMemberDiffBuilder,
		private readonly ProjectFeatureToggleService $projectFeatureToggleService,
		private readonly ConvertChatService $convertChatService,
		private readonly ProjectNotificationSettingsService $notificationSettingsService,
		private readonly MemberEntityMapper $memberEntityMapper,
		private readonly StructureRelationService $structureRelationService,
	)
	{
	}

	public function update(
		Project $project,
		int $userId,
		bool $isCurrentUserModuleAdmin = false,
	): Result
	{
		$projectId = $project->id;
		if ($projectId === null)
		{
			return (new Result())->addError(new Error('Project id is required'));
		}

		$baseFeatureId = trim((string)$project->baseFeatureId);
		if (!$this->isBaseFeatureAvailable($projectId, $baseFeatureId))
		{
			return (new Result())->addError(new Error('Base feature is invalid'));
		}

		if ($project->tagNames !== null)
		{
			$tagValidation = $this->projectTagRepository->validate($project->tagNames);
			if (!$tagValidation->isSuccess())
			{
				return (new Result())->addErrors($tagValidation->getErrors());
			}
		}

		$data = ['id' => $projectId];

		if ($project->name !== null)
		{
			$data['name'] = $project->name;
		}

		if ($project->description !== null)
		{
			$data['description'] = $project->description;
		}

		if ($project->privacyType !== null)
		{
			$data['viewMode'] = $project->privacyType === PrivacyType::Open
				? ViewMode::OPEN->value
				: ViewMode::SECRET->value;
		}

		if ($project->ownerId !== null)
		{
			$data['ownerId'] = $project->ownerId;
		}

		if ($project->rawPermissions !== null)
		{
			$data['permissions'] = $project->rawPermissions;
		}

		if ($project->options !== null)
		{
			$allowedOptionNames = array_keys(OptionFactory::DEFAULT_OPTIONS);
			$filteredOptions = array_intersect_key(
				$project->options,
				array_flip($allowedOptionNames),
			);
			if ($filteredOptions !== [])
			{
				$data['options'] = $filteredOptions;
			}
		}

		$featureStates = [];
		if ($project->features !== null)
		{
			$featureStates = $this->projectFeatureToggleService->filterFeaturesForUpdate($projectId, $project->features);
		}

		$data = [
			...$data,
			...$this->projectAvatarLegacyPayloadBuilder->buildForUpdate($project->avatar),
		];
		$members = $this->ensureInitiatorIsMember(
			ownerId: $project->ownerId,
			userId: $userId,
			isCurrentUserModuleAdmin: $isCurrentUserModuleAdmin,
			members: $project->members,
			moderatorMembers: $project->moderatorMembers,
		);
		$projectData = $project->toArray();
		$projectData['members'] = $members?->toArray();
		$projectData['moderatorMembers'] = $project->moderatorMembers?->toArray();

		$memberDiff = $this->projectMemberDiffBuilder->build($projectId, $projectData);
		$teamsSupportedResult = $this->structureRelationService->validateTeamsSupported(
			$memberDiff['addMembers'] ?? [],
		);
		if (!$teamsSupportedResult->isSuccess())
		{
			return $teamsSupportedResult;
		}

		$visibilityResult = $this->validateTeamsVisibility($project->members, $userId);
		if (!$visibilityResult->isSuccess())
		{
			return $visibilityResult;
		}

		if (array_key_exists('deleteMembers', $memberDiff))
		{
			$memberDiff['deleteMembers'] = $this->filterVisibleMemberCodes(
				$memberDiff['deleteMembers'],
				$userId,
			);
		}
		$data = [
			...$data,
			...$memberDiff,
		];

		$updateCommand = CollabUpdateCommand::createFromArray($data)
			->setInitiatorId($userId)
		;

		/** @var CollabResult $result */
		$result = $this->collabService->update($updateCommand);

		$collab = $result->getCollab();
		if ($collab === null)
		{
			return (new Result())->addErrors($result->getErrors());
		}

		if ($project->privacyType !== null)
		{
			$this->convertChatService->convertChat(
				chatId: $collab->getChatId(),
				type: Type::Collab,
				groupTitle: $collab->getName(),
				isOpened: $collab->isOpened(),
			);
		}

		if ($project->dates !== null || $project->publication !== null || $project->goal !== null)
		{
			$this->workgroupFieldService->saveGroupFields(
				id: $projectId,
				dates: $project->dates,
				publication: $project->publication,
				goal: $project->goal,
			);
		}

		if ($project->tagNames !== null)
		{
			$tagResult = $this->projectTagRepository->save($projectId, $project->tagNames);
			if (!$tagResult->isSuccess())
			{
				return (new Result())->addErrors($tagResult->getErrors());
			}
		}

		if ($featureStates !== [])
		{
			$featureResult = $this->projectFeatureToggleService->saveFeatureStates($projectId, $featureStates);
			if (!$featureResult->isSuccess())
			{
				return (new Result())->addErrors($featureResult->getErrors());
			}
		}

		if ($baseFeatureId !== '')
		{
			$baseFeatureResult = $this->projectFeatureToggleService->saveBaseFeatureId($projectId, $baseFeatureId);
			if (!$baseFeatureResult->isSuccess())
			{
				return (new Result())->addErrors($baseFeatureResult->getErrors());
			}
		}

		if ($project->notifications !== null)
		{
			$this->notificationSettingsService->save($projectId, $project->notifications);
		}

		return (new Result())->setData([
			'projectId' => $collab->getId(),
			// Membership delta applied by this edit, surfaced so the explicit-edit boundary
			// (UpdateProjectHandler) can emit member analytics. Copy reuses update() directly and
			// therefore never reports these as user-driven member changes.
			'addedMembers' => $this->memberEntityMapper->fromAccessCodes($memberDiff['addMembers'] ?? []),
			'removedMembers' => $this->memberEntityMapper->fromAccessCodes($memberDiff['deleteMembers'] ?? []),
		]);
	}

	private function isBaseFeatureAvailable(int $projectId, string $baseFeatureId): bool
	{
		if ($baseFeatureId === '')
		{
			return true;
		}

		return $this->projectFeatureToggleService->canSetBaseFeature($projectId, $baseFeatureId);
	}

	/**
	 * Every team the client sends is judged, whether or not the project already holds it. Judging only what the
	 * diff turns into an addition would let success and refusal tell a linked team from an unlinked one, and an
	 * editor could find a team hidden from them by trying ids.
	 *
	 * Visibility, not the right to edit: the provider hands the editor a composition already filtered by
	 * visibility, so a team the editor sees but may not edit comes back with every save and must not refuse it.
	 */
	private function validateTeamsVisibility(?MemberEntityCollection $members, int $userId): Result
	{
		$teams = $members?->filterByType(MemberEntityType::Team);
		if ($teams === null || $teams->isEmpty())
		{
			return new Result();
		}

		return $this->structureRelationService->validateNodesVisibility(
			$this->memberEntityMapper->toAccessCodes($teams),
			$userId,
		);
	}

	/**
	 * @param string[] $accessCodes
	 * @return string[]
	 */
	private function filterVisibleMemberCodes(array $accessCodes, int $userId): array
	{
		if (empty($accessCodes))
		{
			return [];
		}

		$members = $this->memberEntityMapper->fromAccessCodes($accessCodes);
		$visibleMembers = $this->structureRelationService->filterVisibleNodesForUser($members, $userId);

		return $this->memberEntityMapper->toAccessCodes($visibleMembers);
	}
}
