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
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectTagRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;
use Bitrix\Socialnetwork\V2\Internal\Entity\PrivacyType;
use Bitrix\Socialnetwork\V2\Internal\Service\Notification\ProjectNotificationSettingsService;
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

		$data = [
			...$data,
			...$this->projectMemberDiffBuilder->build($projectId, $projectData),
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
			$this->projectTagRepository->save($projectId, $project->tagNames);
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

		return (new Result())->setData(['projectId' => $collab->getId()]);
	}

	private function isBaseFeatureAvailable(int $projectId, string $baseFeatureId): bool
	{
		if ($baseFeatureId === '')
		{
			return true;
		}

		return $this->projectFeatureToggleService->canSetBaseFeature($projectId, $baseFeatureId);
	}
}
