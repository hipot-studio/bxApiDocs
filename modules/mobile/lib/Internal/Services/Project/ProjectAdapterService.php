<?php

namespace Bitrix\Mobile\Internal\Services\Project;

use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Mobile\Internal\Dto\Project\ProjectCreateDto;
use Bitrix\Mobile\Internal\Dto\Project\ProjectSaveResultDto;
use Bitrix\Socialnetwork\Collab\Registry\CollabRegistry;
use Bitrix\Socialnetwork\V2\Public\Command\Project\AddProjectCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\UpdateProjectCommand;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\Project as ProjectDto;
use Throwable;

Loc::loadMessages(__FILE__);

final class ProjectAdapterService
{
	private const PROJECT_TYPE_PUBLIC = 'public';
	private const PROJECT_TYPE_PRIVATE = 'private';

	private const PRIVACY_TYPE_OPEN = 'open';
	private const PRIVACY_TYPE_CLOSED = 'closed';

	private const MEMBER_TYPE_USER = 'user';
	private const MEMBER_TYPE_DEPARTMENT = 'department';

	private const FEATURE_TASKS = 'tasks';
	private const FEATURE_KNOWLEDGE = 'landing_knowledge';

	private const TASK_OPERATION_VIEW = 'view';
	private const TASK_OPERATION_SORT = 'sort';
	private const TASK_OPERATION_CREATE = 'create_tasks';
	private const TASK_OPERATION_EDIT = 'edit_tasks';
	private const TASK_OPERATION_DELETE = 'delete_tasks';

	private const KNOWLEDGE_OPERATION_READ = 'read';
	private const KNOWLEDGE_OPERATION_EDIT = 'edit';
	private const KNOWLEDGE_OPERATION_SETTINGS = 'sett';
	private const KNOWLEDGE_OPERATION_DELETE = 'delete';

	public function __construct(
		private readonly int $currentUserId,
		private readonly ProjectSettingsValidator $settingsValidator,
		private readonly OperationResultErrorResolver $errorResolver,
		private readonly ProjectChatSettingsService $projectChatSettingsService,
		private readonly ProjectLegacyUpdateService $projectLegacyUpdateService,
	)
	{
	}

	public function create(ProjectCreateDto $project): ProjectSaveResultDto
	{
		$this->settingsValidator->validateCreateDto($project);

		$projectId = $this->runCreateCommand($project);
		$chatId = $this->projectChatSettingsService->ensureChatId($projectId);

		return new ProjectSaveResultDto(
			projectId: $projectId,
			chatId: $chatId,
		);
	}

	public function update(int $projectId, ProjectCreateDto $project): ProjectSaveResultDto
	{
		if ($projectId <= 0)
		{
			throw new \RuntimeException(Loc::getMessage('MOBILE_PROJECT_ADAPTER_SERVICE_UPDATE_FAILED'));
		}

		$this->settingsValidator->validateCreateDto($project);

		if ($this->isLegacyProject($projectId))
		{
			try
			{
				$projectId = $this->projectLegacyUpdateService->update($projectId, $project);
			}
			catch (Throwable $exception)
			{
				throw new \RuntimeException($this->resolveSaveErrorMessage(
					defaultMessageCode: 'MOBILE_PROJECT_ADAPTER_SERVICE_UPDATE_FAILED',
					exception: $exception,
				));
			}
		}
		else
		{
			$projectId = $this->runUpdateCommand($this->mapProjectDto($project, $projectId));
		}

		return new ProjectSaveResultDto(projectId: $projectId);
	}

	public function updateChatSettings(
		int $projectId,
		?string $messageWriters = null,
		?string $showHistory = null,
		?int $messagesAutoDeleteDelay = null,
	): void
	{
		$this->settingsValidator->validateChatSettings($messageWriters, $showHistory, $messagesAutoDeleteDelay);

		$options = array_filter([
			'manageMessages' => $messageWriters,
			'showHistory' => $showHistory,
			'messagesAutoDeleteDelay' => $messagesAutoDeleteDelay !== null
				? (string)$messagesAutoDeleteDelay
				: null,
		], static fn(mixed $value): bool => $value !== null);

		if ($options === [])
		{
			return;
		}

		$this->runUpdateCommand(
			ProjectDto::mapFromArray([
				'id' => $projectId,
				'options' => $this->mergeOptionsForUpdate($projectId, $options),
			]),
			'MOBILE_PROJECT_ADAPTER_SERVICE_UPDATE_CHAT_SETTINGS_FAILED',
		);
	}

	public function updateTaskPermissions(
		int $projectId,
		string $viewAll,
		string $sort,
		string $createTasks,
		string $editTasks,
		string $deleteTasks,
	): void
	{
		$this->settingsValidator->validateTaskPermissions($viewAll, $sort, $createTasks, $editTasks, $deleteTasks);

		$this->runUpdateCommand(
			ProjectDto::mapFromArray([
				'id' => $projectId,
				'permissions' => [
					self::FEATURE_TASKS => [
						self::TASK_OPERATION_VIEW => $viewAll,
						self::TASK_OPERATION_SORT => $sort,
						self::TASK_OPERATION_CREATE => $createTasks,
						self::TASK_OPERATION_EDIT => $editTasks,
						self::TASK_OPERATION_DELETE => $deleteTasks,
					],
				],
			]),
			'MOBILE_PROJECT_ADAPTER_SERVICE_UPDATE_TASK_PERMISSIONS_FAILED',
		);
	}

	public function updateKnowledgePermissions(
		int $projectId,
		string $read,
		string $edit,
		string $settings,
		string $delete,
	): void
	{
		$this->settingsValidator->validateKnowledgePermissions($read, $edit, $settings, $delete);
		$this->ensureKnowledgeFeatureEnabled($projectId);

		$this->runUpdateCommand(
			ProjectDto::mapFromArray([
				'id' => $projectId,
				'permissions' => [
					self::FEATURE_KNOWLEDGE => [
						self::KNOWLEDGE_OPERATION_READ => $read,
						self::KNOWLEDGE_OPERATION_EDIT => $edit,
						self::KNOWLEDGE_OPERATION_SETTINGS => $settings,
						self::KNOWLEDGE_OPERATION_DELETE => $delete,
					],
				],
			]),
			'MOBILE_PROJECT_ADAPTER_SERVICE_UPDATE_KNOWLEDGE_PERMISSIONS_FAILED',
		);
	}

	private function runCreateCommand(ProjectCreateDto $project): int
	{
		$dto = $this->mapProjectDto($project);

		try
		{
			$result = (new AddProjectCommand($dto, $this->currentUserId))->run();
		}
		catch (CommandValidationException $exception)
		{
			throw new \RuntimeException($this->resolveSaveErrorMessage(
				defaultMessageCode: 'MOBILE_PROJECT_ADAPTER_SERVICE_CREATE_FAILED',
				validationErrors: $exception->getValidationErrors(),
				exception: $exception,
			));
		}
		catch (CommandException $exception)
		{
			throw new \RuntimeException($this->resolveSaveErrorMessage(
				defaultMessageCode: 'MOBILE_PROJECT_ADAPTER_SERVICE_CREATE_FAILED',
				exception: $exception->getPrevious() ?? $exception,
			));
		}

		if (!$result->isSuccess())
		{
			throw new \RuntimeException($this->resolveSaveErrorMessage(
				defaultMessageCode: 'MOBILE_PROJECT_ADAPTER_SERVICE_CREATE_FAILED',
				result: $result,
			));
		}

		return $this->extractProjectId($result, 'MOBILE_PROJECT_ADAPTER_SERVICE_CREATE_FAILED');
	}

	private function runUpdateCommand(
		ProjectDto $dto,
		string $defaultMessageCode = 'MOBILE_PROJECT_ADAPTER_SERVICE_UPDATE_FAILED',
	): int
	{
		try
		{
			$result = (new UpdateProjectCommand($dto, $this->currentUserId))->run();
		}
		catch (CommandValidationException $exception)
		{
			throw new \RuntimeException($this->resolveSaveErrorMessage(
				defaultMessageCode: $defaultMessageCode,
				validationErrors: $exception->getValidationErrors(),
				exception: $exception,
			));
		}
		catch (CommandException $exception)
		{
			throw new \RuntimeException($this->resolveSaveErrorMessage(
				defaultMessageCode: $defaultMessageCode,
				exception: $exception->getPrevious() ?? $exception,
			));
		}

		if (!$result->isSuccess())
		{
			throw new \RuntimeException($this->resolveSaveErrorMessage(
				defaultMessageCode: $defaultMessageCode,
				result: $result,
			));
		}

		return $this->extractProjectId($result, $defaultMessageCode);
	}

	private function extractProjectId(Result $result, string $defaultMessageCode): int
	{
		$projectId = (int)($result->getData()['projectId'] ?? 0);
		if ($projectId <= 0)
		{
			throw new \RuntimeException(Loc::getMessage($defaultMessageCode));
		}

		return $projectId;
	}

	private function mapProjectDto(ProjectCreateDto $project, ?int $projectId = null): ProjectDto
	{
		$options = $this->mapOptions($project);
		if ($projectId !== null)
		{
			$options = $this->mergeOptionsForUpdate($projectId, $options);
		}

		return ProjectDto::mapFromArray(array_filter([
			'id' => $projectId,
			'name' => $project->name,
			'description' => $project->description,
			'ownerId' => $project->ownerId > 0 ? $project->ownerId : null,
			'privacyType' => $this->mapProjectTypeToPrivacyType($project->type),
			'members' => $this->mapMembers($project),
			'moderatorMembers' => $this->mapModeratorMembers($project),
			'permissions' => $this->mapPermissions($project),
			'options' => $options,
			'dates' => $this->mapDates($project),
			'tags' => $project->tags,
			'avatar' => $project->avatar,
		], static fn(mixed $value): bool => $value !== null));
	}

	private function mapMembers(ProjectCreateDto $project): array
	{
		$result = [];

		foreach ($this->getParticipantUserIds($project) as $userId)
		{
			$result[] = [
				'id' => $userId,
				'type' => self::MEMBER_TYPE_USER,
			];
		}

		foreach ($project->participantDepartmentIds as $departmentId)
		{
			$result[] = [
				'id' => $departmentId,
				'type' => self::MEMBER_TYPE_DEPARTMENT,
				'withChildNodes' => true,
			];
		}

		return $result;
	}

	private function mapModeratorMembers(ProjectCreateDto $project): array
	{
		$result = [];

		foreach ($this->getModeratorIds($project) as $userId)
		{
			$result[] = [
				'id' => $userId,
				'type' => self::MEMBER_TYPE_USER,
			];
		}

		return $result;
	}

	private function mapPermissions(ProjectCreateDto $project): array
	{
		return [
			self::FEATURE_TASKS => [
				self::TASK_OPERATION_VIEW => $project->taskViewAll,
				self::TASK_OPERATION_SORT => $project->taskSort,
				self::TASK_OPERATION_CREATE => $project->taskCreateTasks,
				self::TASK_OPERATION_EDIT => $project->taskEditTasks,
				self::TASK_OPERATION_DELETE => $project->taskDeleteTasks,
			],
			self::FEATURE_KNOWLEDGE => [
				self::KNOWLEDGE_OPERATION_READ => $project->knowledgeRead,
				self::KNOWLEDGE_OPERATION_EDIT => $project->knowledgeEdit,
				self::KNOWLEDGE_OPERATION_SETTINGS => $project->knowledgeSettings,
				self::KNOWLEDGE_OPERATION_DELETE => $project->knowledgeDelete,
			],
		];
	}

	private function mapOptions(ProjectCreateDto $project): array
	{
		return array_filter([
			'whoCanInvite' => $project->initiatePerms,
			'manageMessages' => $project->messageWriters,
			'showHistory' => $project->showHistory,
			'messagesAutoDeleteDelay' => $project->messagesAutoDeleteDelay !== null
				? (string)$project->messagesAutoDeleteDelay
				: null,
		], static fn(mixed $value): bool => $value !== null);
	}

	private function mergeOptionsForUpdate(int $projectId, array $options): array
	{
		return array_replace(
			$this->projectChatSettingsService->getCurrentOptions($projectId),
			$options,
		);
	}

	private function mapDates(ProjectCreateDto $project): ?array
	{
		$startTs = $this->mapDateToTimestamp($project->dateStart);
		$finishTs = $this->mapDateToTimestamp($project->dateFinish);

		if ($startTs === null && $finishTs === null)
		{
			return null;
		}

		return array_filter([
			'startTs' => $startTs,
			'finishTs' => $finishTs,
		], static fn(mixed $value): bool => $value !== null);
	}

	private function mapDateToTimestamp(?string $date): ?int
	{
		if ($date === null || $date === '')
		{
			return null;
		}

		$timestamp = strtotime($date);

		return ($timestamp !== false) ? $timestamp : null;
	}

	private function getParticipantUserIds(ProjectCreateDto $project): array
	{
		$moderatorIds = $this->getModeratorIds($project);

		return array_values(array_diff(
			$project->participantUserIds,
			$moderatorIds,
			[$project->ownerId],
		));
	}

	private function getModeratorIds(ProjectCreateDto $project): array
	{
		return array_values(array_diff($project->moderatorIds, [$project->ownerId]));
	}

	private function mapProjectTypeToPrivacyType(string $type): string
	{
		return $type === self::PROJECT_TYPE_PRIVATE
			? self::PRIVACY_TYPE_CLOSED
				: self::PRIVACY_TYPE_OPEN;
	}

	private function ensureKnowledgeFeatureEnabled(int $projectId): void
	{
		$featureId = \CSocNetFeatures::SetFeature(
			SONET_ENTITY_GROUP,
			$projectId,
			self::FEATURE_KNOWLEDGE,
			true,
		);

		if (!$featureId)
		{
			throw new \RuntimeException(Loc::getMessage('MOBILE_PROJECT_ADAPTER_SERVICE_ENABLE_KNOWLEDGE_FAILED'));
		}
	}

	private function resolveSaveErrorMessage(
		string $defaultMessageCode,
		array $validationErrors = [],
		?Result $result = null,
		?Throwable $exception = null,
	): string
	{
		global $APPLICATION;

		$applicationException = $APPLICATION?->GetException();
		$applicationErrorId = $this->getApplicationErrorId($applicationException);

		if ($applicationErrorId === 'ERROR_GROUP_NAME_EXISTS')
		{
			return Loc::getMessage('MOBILE_PROJECT_ADAPTER_SERVICE_NAME_ALREADY_EXISTS');
		}

		$applicationErrorMessage = $this->getApplicationErrorMessage($applicationException);
		if ($applicationErrorMessage !== '')
		{
			return $applicationErrorMessage;
		}

		$validationErrorMessage = $this->extractValidationErrorMessage($validationErrors);
		if ($validationErrorMessage !== '')
		{
			return $validationErrorMessage;
		}

		if ($result !== null)
		{
			$resultErrorMessage = trim($this->errorResolver->resolve($result, ''));
			if ($resultErrorMessage !== '')
			{
				return $resultErrorMessage;
			}
		}

		$exceptionMessage = trim((string)($exception?->getMessage() ?? ''));
		if ($exceptionMessage !== '' && !in_array($exceptionMessage, ['Cannot create group', 'Cannot update group'], true))
		{
			return $exceptionMessage;
		}

		return Loc::getMessage($defaultMessageCode);
	}

	private function extractValidationErrorMessage(array $validationErrors): string
	{
		foreach ($validationErrors as $validationError)
		{
			if (is_object($validationError) && method_exists($validationError, 'getMessage'))
			{
				$message = trim((string)$validationError->getMessage());
				if ($message !== '')
				{
					return $message;
				}
			}
		}

		return '';
	}

	private function getApplicationErrorId(mixed $applicationException): string
	{
		if (!is_object($applicationException))
		{
			return '';
		}

		$errorId = $applicationException->GetID() ?? '';
		if ($errorId !== '')
		{
			return $errorId;
		}

		$messages = $applicationException->messages ?? null;

		return is_array($messages) && is_array($messages[0] ?? null)
			? (string)($messages[0]['id'] ?? '')
			: '';
	}

	private function getApplicationErrorMessage(mixed $applicationException): string
	{
		if (!is_object($applicationException) || !method_exists($applicationException, 'GetString'))
		{
			return '';
		}

		return trim((string)$applicationException->GetString());
	}

	private function isLegacyProject(int $projectId): bool
	{
		return CollabRegistry::getInstance()->get($projectId) === null;
	}
}
