<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Collab\Control\Command\CollabAddCommand;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\ConvertStatusOption;
use Bitrix\Socialnetwork\Control\Enum\ViewMode;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertStatus;
use Bitrix\Socialnetwork\V2\Internal\Entity\PrivacyType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectTagRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Service\Notification\ProjectNotificationSettingsService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\FeatureDictionary;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\InitiatorMemberTrait;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectAvatarLegacyPayloadBuilder;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectCollabAddService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectCreateFeatures;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectFeatureToggleService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectInputNormalizer;

class AddProjectService
{
	use InitiatorMemberTrait;

	public function __construct(
		private readonly ProjectCollabAddService $projectCollabAddService,
		private readonly ProjectTagRepositoryInterface $projectTagRepository,
		private readonly MemberEntityMapper $memberEntityMapper,
		private readonly ProjectCreateFeatures $projectCreateFeatures,
		private readonly ProjectFeatureToggleService $projectFeatureToggleService,
		private readonly ProjectAvatarLegacyPayloadBuilder $projectAvatarLegacyPayloadBuilder,
		private readonly ProjectInputNormalizer $projectInputNormalizer,
		private readonly WorkgroupFieldService $workgroupFieldService,
		private readonly ProjectNotificationSettingsService $notificationSettingsService,
		private readonly ProjectAnalytics $projectAnalytics,
	)
	{
	}

	public function add(
		Project $project,
		int $userId,
		bool $isCurrentUserModuleAdmin = false,
		bool $enforceInitiatorMembership = true,
	): Result
	{
		[$members, $moderatorMembers] = $this->projectInputNormalizer->normalizeMemberCollections(
			$project->members,
			$project->moderatorMembers,
		);
		if ($enforceInitiatorMembership)
		{
			$members = $this->ensureInitiatorIsMember(
				ownerId: $project->ownerId,
				userId: $userId,
				isCurrentUserModuleAdmin: $isCurrentUserModuleAdmin,
				members: $members,
				moderatorMembers: $moderatorMembers,
			);
		}

		$data = array_filter([
			'ownerId' => $project->ownerId ?? $userId,
			'name' => $project->name,
			'description' => $project->description,
			'features' => $this->projectCreateFeatures->getFeatures($project->features),
			'permissions' => $project->rawPermissions,
			'members' => $members !== null
				? $this->memberEntityMapper->toAccessCodes($members)
				: null,
			'moderatorMembers' => $moderatorMembers !== null
				? $this->memberEntityMapper->toAccessCodes($moderatorMembers)
				: null,
			'options' => $project->options,
			'goal' => $project->goal,
		], static fn(mixed $v): bool => $v !== null);
		$data = array_merge(
			$data,
			$this->projectAvatarLegacyPayloadBuilder->buildForAdd($project->avatar),
		);

		$data['viewMode'] = $project->privacyType === PrivacyType::Closed
			? ViewMode::SECRET->value
			: ViewMode::OPEN->value;

		$baseFeatureId = trim((string)$project->baseFeatureId);
		if (!$this->isBaseFeatureAvailable($baseFeatureId))
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

		$addCommand = CollabAddCommand::createFromArray($data)
			->setInitiatorId($userId)
			->addOption(new ConvertStatusOption(ConvertStatus::NotRequired->value))
		;

		if ($project->dates?->start !== null)
		{
			$addCommand->setDateStart($project->dates->start);
		}

		if ($project->dates?->finish !== null)
		{
			$addCommand->setDateFinish($project->dates->finish);
		}

		// Structure nodes are validated one layer down, by CollabService::validateStructureNodes(), so that the
		// create and the update paths refuse the same composition with the same code.
		$result = $this->projectCollabAddService->addProject(
			command: $addCommand,
			project: $project,
		);

		$isOpened = $project->privacyType !== PrivacyType::Closed;

		$collab = $result->getCollab();
		if ($collab === null)
		{
			return (new Result())->addErrors($result->getErrors());
		}

		$projectId = $collab->getId();

		$this->projectAnalytics->trackSetProjectName(
			groupId: $projectId,
			name: $project->name,
			isOpened: $isOpened,
		);

		if ($baseFeatureId !== '')
		{
			$featureResult = $this->projectFeatureToggleService->saveBaseFeatureId($projectId, $baseFeatureId);
			if (!$featureResult->isSuccess())
			{
				return (new Result())->addErrors($featureResult->getErrors());
			}
		}

		if ($project->tagNames !== null)
		{
			$tagResult = $this->projectTagRepository->save($projectId, $project->tagNames);
			if (!$tagResult->isSuccess())
			{
				return (new Result())->addErrors($tagResult->getErrors());
			}
		}

		if ($project->publication !== null)
		{
			$this->workgroupFieldService->saveGroupFields(
				id: $projectId,
				dates: null,
				publication: $project->publication,
			);
		}

		if ($project->notifications !== null)
		{
			$this->notificationSettingsService->save($projectId, $project->notifications);
		}

		return (new Result())->setData(['projectId' => $projectId]);
	}

	private function isBaseFeatureAvailable(string $baseFeatureId): bool
	{
		if ($baseFeatureId === '')
		{
			return true;
		}

		return in_array($baseFeatureId, FeatureDictionary::getBaseFeatureIds(), true);
	}
}
