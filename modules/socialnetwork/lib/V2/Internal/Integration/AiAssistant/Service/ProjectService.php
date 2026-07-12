<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Service;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Feature;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Dto\FindProjectByNameDto;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectMemberRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Service\Link\LinkService;
use Bitrix\Socialnetwork\V2\Internal\Service\ProjectOptionService;
use Bitrix\Tasks\V2\Public\Provider\Params\Stage\StageParams;
use Bitrix\Tasks\V2\Public\Provider\StageProvider;
use CSocNetGroup;
use CSocNetUser;

class ProjectService
{
	public const SEARCH_LIMIT = 10;

	public function __construct(
		private readonly ProjectMemberRepositoryInterface $memberRepository,
		private readonly ProjectRepositoryInterface $projectRepository,
		private readonly LinkService $linkService,
		private readonly ProjectOptionService $projectOptionService,
	)
	{
	}

	public function findByName(FindProjectByNameDto $dto, int $userId): array
	{
		$queries = $this->normalizeQueries($dto->searchQueries ?? []);
		if (empty($queries))
		{
			return ['candidates' => [], 'hasMore' => false];
		}

		$projects = $this->findProjectsByQueries($queries, $userId, self::SEARCH_LIMIT + 1);

		$hasMore = count($projects) > self::SEARCH_LIMIT;
		if ($hasMore)
		{
			$projects = array_slice($projects, 0, self::SEARCH_LIMIT);
		}

		$projectIds = array_map(static fn(array $project): int => (int)($project['ID'] ?? 0), $projects);
		$rolesByProjectIdMap = $this->memberRepository->getMemberRoles($projectIds, $userId);

		return [
			'candidates' => $this->buildCandidates($projects, $rolesByProjectIdMap),
			'hasMore' => $hasMore,
		];
	}

	public function getForAnalysis(int $projectId, int $userId): ?array
	{
		$project = $this->projectRepository->getNameAndType($projectId);
		if ($project === null)
		{
			return null;
		}

		$result = [
			'projectId' => $projectId,
			'name' => $project['name'],
			'type' => $project['type'],
		];

		$displayType = $this->getDisplayType($projectId, (string)$project['type']);
		if ($displayType !== null)
		{
			$result['displayType'] = $displayType;
		}

		$result['stages'] = $this->getProjectStageNames($projectId, $userId);
		$result['link'] = $this->linkService->getForProject($projectId);

		return $result;
	}

	protected function findProjectsByQueries(array $queries, int $userId, int $limit): array
	{
		$filter = [
			'%NAME' => $queries,
			'CLOSED' => 'N',
		];

		if (!CSocNetUser::isUserModuleAdmin($userId))
		{
			$filter['CHECK_PERMISSIONS'] = $userId;
		}

		$result = CSocNetGroup::getList(
			arOrder: ['NAME' => 'ASC'],
			arFilter: $filter,
			arNavStartParams: ['nTopCount' => $limit],
			arSelectFields: ['ID', 'NAME'],
		);

		$projects = [];
		while ($project = $result->fetch())
		{
			$projects[] = $project;
		}

		return $projects;
	}

	protected function getDisplayType(int $projectId, string $rawType): ?string
	{
		if ($rawType !== Type::Collab->value || !Feature::isNewProjectsOn())
		{
			return null;
		}

		return
			$this->projectOptionService->isCollabConverted($projectId)
				? Type::Project->value
				: null
			;
	}

	protected function getProjectStageNames(int $projectId, int $userId): array
	{
		if (!Loader::includeModule('tasks'))
		{
			return [];
		}

		$stageProvider = new StageProvider();

		$stages = $stageProvider->getByGroupId(new StageParams(
			groupId: $projectId,
			userId: $userId,
			checkAccess: true,
		));

		$names = [];
		foreach ($stages as $stage)
		{
			$title = trim((string)$stage->title);
			if ($title !== '')
			{
				$names[] = $title;
			}
		}

		return $names;
	}

	private function normalizeQueries(array $queries): array
	{
		$normalizedMap = [];

		foreach ($queries as $query)
		{
			$query = trim((string)$query);
			if ($query === '')
			{
				continue;
			}

			$normalizedMap[$query] = true;
		}

		return array_keys($normalizedMap);
	}

	private function buildCandidates(array $projects, array $rolesByProjectIdMap): array
	{
		return array_map(
			fn(array $row): array => $this->buildCandidate($row, $rolesByProjectIdMap),
			$projects,
		);
	}

	private function buildCandidate(array $row, array $rolesByProjectIdMap): array
	{
		$projectId = (int)$row['ID'];

		return [
			'projectId' => $projectId,
			'name' => (string)$row['NAME'],
			'isUserMember' => isset($rolesByProjectIdMap[$projectId]),
			'link' => $this->linkService->getForProject($projectId),
		];
	}
}
