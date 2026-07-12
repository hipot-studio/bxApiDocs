<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageFactory;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;
use Bitrix\Socialnetwork\Internals\Registry\GroupRegistry;
use Bitrix\Socialnetwork\Provider\EmployeeProvider;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertStatus;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\ProjectDates;
use Bitrix\Socialnetwork\V2\Internal\Exceptions\ProjectNameExistsException;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\ConvertProgressMapper;
use Bitrix\Socialnetwork\V2\Internal\Repository\CollabOptionRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectMemberRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\WorkgroupRepository;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\LegacyProjectCopyService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectCopyFeatureService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectFeatureWhitelistService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectInputNormalizer;

class CopyProjectService
{
	private const GROUP_COPY_ERROR_CODE = 'GROUP_COPY_ERROR';

	public function __construct(
		private readonly LegacyProjectCopyService $legacyProjectCopyService,
		private readonly UpdateProjectService $updateProjectService,
		private readonly ProjectRepositoryInterface $projectRepository,
		private readonly CollabOptionRepository $collabOptionRepository,
		private readonly ProjectMemberRepositoryInterface $projectMemberRepository,
		private readonly MemberEntityMapper $memberEntityMapper,
		private readonly ProjectInputNormalizer $projectInputNormalizer,
		private readonly EmployeeProvider $employeeProvider,
		private readonly ProjectFeatureWhitelistService $projectFeatureWhitelistService,
		private readonly ProjectCopyFeatureService $projectCopyFeatureService,
		private readonly WorkgroupRepository $workgroupRepository,
	)
	{
	}

	public function copy(
		int $sourceProjectId,
		Project $project,
		?array $copyOptions,
		int $userId,
		bool $isCurrentUserModuleAdmin = false,
	): Result
	{
		// Mirror Add/Update behavior: surface ERROR_GROUP_NAME_EXISTS to the caller.
		// Legacy copy wraps the original ThrowException ID into GROUP_COPY_ERROR and drops the code,
		// so the duplicate-name signal is lost — re-check here and signal via a typed exception.
		if ($project->name !== null && $this->workgroupRepository->isNameExists($project->name))
		{
			throw new ProjectNameExistsException();
		}

		$copyResult = $this->legacyProjectCopyService->copy(
			sourceProjectId: $sourceProjectId,
			project: $project,
			userId: $userId,
		);
		if (!$copyResult->isSuccess())
		{
			$this->throwIfNameExistsRaceCondition($copyResult, $project);

			return $copyResult;
		}

		$projectId = (int)($copyResult->getData()['projectId'] ?? 0);
		if ($projectId <= 0)
		{
			return (new Result())->addError(new Error('Failed to retrieve copied project ID'));
		}

		$this->collabOptionRepository->setOption(
			collabId: $projectId,
			optionName: ConvertProgressMapper::CONVERT_STATUS,
			value: ConvertStatus::NotRequired->value,
		);

		GroupRegistry::getInstance()->invalidate($projectId);

		$this->sendProjectCreatedMessages($projectId, $userId);

		$projectTerm = $this->extractProjectTerm($copyResult);

		$this->projectFeatureWhitelistService->syncWithCreateDefaults($projectId);

		[$members, $moderatorMembers] = $this->resolveMembers(
			sourceProjectId: $sourceProjectId,
			project: $project,
		);
		$targetProject = $this->buildTargetProject(
			projectId: $projectId,
			sourceProjectId: $sourceProjectId,
			project: $project,
			projectTerm: $projectTerm,
			members: $members,
			moderatorMembers: $moderatorMembers,
		);

		if ($this->requiresUpdate($targetProject))
		{
			$updateResult = $this->updateProjectService->update(
				project: $targetProject,
				userId: $userId,
				isCurrentUserModuleAdmin: $isCurrentUserModuleAdmin,
			);
			if (!$updateResult->isSuccess())
			{
				return $this->attachCreatedProjectId($updateResult, $projectId);
			}
		}

		$featureCopyResult = $this->projectCopyFeatureService->copy(
			sourceProjectId: $sourceProjectId,
			targetProjectId: $projectId,
			projectTerm: $projectTerm,
			copyOptions: $copyOptions,
			userId: $userId,
		);
		if (!$featureCopyResult->isSuccess())
		{
			return $this->attachCreatedProjectId($featureCopyResult, $projectId);
		}

		return (new Result())->setData(['projectId' => $projectId]);
	}

	private function sendProjectCreatedMessages(int $projectId, int $userId): void
	{
		$actionMessageFactory = ActionMessageFactory::getInstance();

		$actionMessageFactory
			->getActionMessage(ActionType::CreateProjectRich, $projectId, $userId)
			->send()
		;

		$actionMessageFactory
			->getActionMessage(ActionType::CreateProject, $projectId, $userId)
			->send()
		;
	}

	/**
	 * @return array{0: ?MemberEntityCollection, 1: ?MemberEntityCollection}
	 */
	private function resolveMembers(int $sourceProjectId, Project $project): array
	{
		$members = $project->members ?? $this->loadSourceMembers($sourceProjectId);
		$moderatorMembers = $project->moderatorMembers ?? $this->loadSourceModerators($sourceProjectId);

		$members = $this->filterGuests($members);
		$moderatorMembers = $this->filterGuests($moderatorMembers);
		[$members, $moderatorMembers] = $this->projectInputNormalizer->normalizeUpdateMemberCollections(
			$members,
			$moderatorMembers,
		);

		return [
			$this->nullIfEmpty($members),
			$this->nullIfEmpty($moderatorMembers),
		];
	}

	private function loadSourceMembers(int $sourceProjectId): ?MemberEntityCollection
	{
		return $this->loadMemberCollection(
			$this->projectMemberRepository->getMemberCodes($sourceProjectId),
		);
	}

	private function loadSourceModerators(int $sourceProjectId): ?MemberEntityCollection
	{
		return $this->loadMemberCollection(
			$this->projectMemberRepository->getModeratorCodes($sourceProjectId),
		);
	}

	private function loadMemberCollection(array $codes): ?MemberEntityCollection
	{
		$collection = $this->memberEntityMapper->fromAccessCodes($codes);

		return $collection->isEmpty() ? null : $collection;
	}

	private function filterGuests(?MemberEntityCollection $members): ?MemberEntityCollection
	{
		if ($members === null || $members->isEmpty())
		{
			return $members;
		}

		$userIds = [];
		foreach ($members as $member)
		{
			if ($member->type === MemberEntityType::User && $member->id !== null)
			{
				$userIds[] = $member->id;
			}
		}

		[$employeeIds, ] = $this->employeeProvider->splitIntoEmployeesAndGuests($userIds);
		$employeeMap = array_fill_keys($employeeIds, true);

		return $members->filter(
			static fn(MemberEntity $member): bool => (
				$member->type !== MemberEntityType::User
				|| isset($employeeMap[$member->id ?? 0])
			),
		);
	}

	private function nullIfEmpty(?MemberEntityCollection $members): ?MemberEntityCollection
	{
		return ($members !== null && $members->isEmpty()) ? null : $members;
	}

	private function buildTargetProject(
		int $projectId,
		int $sourceProjectId,
		Project $project,
		?array $projectTerm,
		?MemberEntityCollection $members,
		?MemberEntityCollection $moderatorMembers,
	): Project
	{
		return new Project(
			id: $projectId,
			goal: $this->resolveGoal($sourceProjectId, $project),
			avatar: $project->avatar,
			dates: $this->resolveEffectiveDates($project, $projectTerm),
			members: $members,
			moderatorMembers: $moderatorMembers,
			rawPermissions: $project->rawPermissions,
			options: $this->resolveOptions($sourceProjectId, $project),
			tagNames: $project->tagNames,
			publication: $project->publication,
		);
	}

	private function resolveGoal(int $sourceProjectId, Project $project): ?string
	{
		if ($project->goal !== null)
		{
			return $project->goal;
		}

		return $this->projectRepository->getById($sourceProjectId)?->goal;
	}

	private function resolveOptions(int $sourceProjectId, Project $project): ?array
	{
		if ($project->options !== null)
		{
			return $project->options;
		}

		return $this->collabOptionRepository->getOptions($sourceProjectId);
	}

	private function requiresUpdate(Project $project): bool
	{
		return $project->goal !== null
			|| $project->avatar !== null
			|| $project->members !== null
			|| $project->moderatorMembers !== null
			|| $project->rawPermissions !== null
			|| $project->options !== null
			|| $project->dates !== null
			|| $project->tagNames !== null
			|| $project->publication !== null;
	}

	private function resolveEffectiveDates(Project $project, ?array $projectTerm): ?ProjectDates
	{
		if ($project->dates === null)
		{
			return null;
		}

		if ($projectTerm === null)
		{
			return $project->dates;
		}

		return new ProjectDates(
			start: $this->parseProjectDate($projectTerm['start_point'] ?? null) ?? $project->dates->start,
			finish: $this->parseProjectDate($projectTerm['end_point'] ?? null) ?? $project->dates->finish,
		);
	}

	private function parseProjectDate(?string $value): ?DateTime
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		return DateTime::createFromUserTime($value);
	}

	private function extractProjectTerm(Result $copyResult): ?array
	{
		$projectTerm = $copyResult->getData()['projectTerm'] ?? null;

		return (is_array($projectTerm) && $projectTerm !== [])
			? $projectTerm
			: null;
	}

	private function attachCreatedProjectId(Result $result, int $projectId): Result
	{
		$data = $result->getData();
		$data['projectId'] ??= $projectId;

		return (new Result())
			->setData($data)
			->addErrors($this->attachProjectIdToErrors($result->getErrors(), $projectId))
		;
	}

	private function throwIfNameExistsRaceCondition(Result $copyResult, Project $project): void
	{
		if ($project->name === null)
		{
			return;
		}

		$hasGroupCopyError = false;
		foreach ($copyResult->getErrors() as $error)
		{
			if ($error->getCode() === self::GROUP_COPY_ERROR_CODE)
			{
				$hasGroupCopyError = true;
				break;
			}
		}

		if (!$hasGroupCopyError)
		{
			return;
		}

		if (!$this->workgroupRepository->isNameExists($project->name))
		{
			return;
		}

		throw new ProjectNameExistsException();
	}

	/**
	 * @param Error[] $errors
	 * @return Error[]
	 */
	private function attachProjectIdToErrors(array $errors, int $projectId): array
	{
		$enrichedErrors = [];

		foreach ($errors as $error)
		{
			$customData = $error->getCustomData();
			if (is_array($customData))
			{
				$customData['projectId'] ??= $projectId;
			}
			elseif ($customData === null)
			{
				$customData = ['projectId' => $projectId];
			}
			else
			{
				$customData = [
					'projectId' => $projectId,
					'customData' => $customData,
				];
			}

			$enrichedErrors[] = new Error(
				$error->getMessage(),
				$error->getCode(),
				$customData,
			);
		}

		return $enrichedErrors;
	}
}
