<?php

namespace Bitrix\TasksMobile\Provider;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Entity\Query;
use Bitrix\Main\Entity\Query\Join;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\SystemException;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Mobile\Provider\UserRepository;
use Bitrix\Socialnetwork\Collab\Internals\CollabOptionTable;
use Bitrix\Socialnetwork\Component\WorkgroupList;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectProvider as SocialnetworkProjectProvider;
use Bitrix\Socialnetwork\V2\Feature;
use Bitrix\Socialnetwork\WorkgroupTable;
use Bitrix\Tasks\Internals\Project\Provider as ProjectProvider;
use Bitrix\Tasksmobile\Dto\ProjectListItemDto;
use Bitrix\TasksMobile\Dto\ProjectV2RequestFilter;

final class ProjectV2Provider
{
	public const MODE_PROJECT = 'tasks_project';
	private const DEFAULT_LIMIT = 20;
	private const DEFAULT_OFFSET = 0;
	private const PRESET_EXTRANET = 'extranet';
	private const HAS_COLLABERS_ALIAS = 'HAS_COLLABERS_OPTION';
	private const HAS_COLLABERS_OPTION_NAME = 'HAS_COLLABERS';
	private const ORDER = [
		'IS_PINNED' => 'DESC',
		'ACTIVITY_DATE' => 'DESC',
		'ID' => 'DESC',
	];
	private const SELECT = [
		'ID',
		'OPENED',
		'CLOSED',
		'VISIBLE',
		'ACTIVITY_DATE',
		'IS_PINNED',
		'SCRUM_MASTER_ID',
	];
	private const COUNTER_MAP = [
		'expired' => 'EXPIRED',
		'new_comments' => 'NEW_COMMENTS',
		'project_expired' => 'PROJECT_EXPIRED',
		'project_new_comments' => 'PROJECT_NEW_COMMENTS',
		'sonettotalexpired' => 'EXPIRED',
		'sonettotalcomments' => 'NEW_COMMENTS',
	];

	public function __construct(
		private readonly int $userId,
		private readonly string $mode = self::MODE_PROJECT,
		private readonly ?PageNavigation $pageNavigation = null,
	)
	{
	}

	/**
	 * @throws SqlQueryException
	 * @throws ObjectPropertyException
	 * @throws ArgumentException
	 * @throws SystemException
	 * @throws LoaderException
	 */
	public function loadItems(ProjectV2RequestFilter $filter): array
	{
		$presetId = $filter->presetId;
		$hasCollabersOnly = $this->shouldFilterByCollabers($presetId);
		if ($hasCollabersOnly)
		{
			$presetId = '';
		}

		$projects = $this->loadProjects(
			filter: $this->normalizeFilter($filter),
			presetId: $presetId,
			hasCollabersOnly: $hasCollabersOnly,
		);

		return $this->buildResult($projects);
	}

	public function getSearchBarPresets(): array
	{
		return [
			'presets' => $this->getPresets(),
			'counters' => [],
		];
	}

	/**
	 * @throws SqlQueryException
	 * @throws ObjectPropertyException
	 * @throws ArgumentException
	 * @throws SystemException
	 * @throws LoaderException
	 */
	public function loadByIds(array $ids = []): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		if (empty($ids))
		{
			return $this->getEmptyResult();
		}

		$projects = $this->loadProjects(
			filter: ['ID' => $ids],
			presetId: '',
			useNavigation: false,
		);

		return $this->buildResult($projects);
	}

	private function normalizeFilter(ProjectV2RequestFilter $filter): array
	{
		$normalizedFilter = [];

		$search = trim($filter->searchString);
		if ($search !== '')
		{
			$normalizedFilter['FIND'] = $search;
		}

		$counterFilterId = mb_strtolower(trim($filter->counterFilterId));
		if ($counterFilterId !== '' && isset(self::COUNTER_MAP[$counterFilterId]))
		{
			$normalizedFilter['COUNTERS'] = self::COUNTER_MAP[$counterFilterId];
		}

		return $normalizedFilter;
	}

	private function getPresets(): array
	{
		$provider = new ProjectProvider(
			$this->userId,
			$this->mode,
			$this->mode === WorkgroupList::MODE_TASKS_PROJECT
				&& class_exists(Feature::class)
				&& Feature::isNewProjectsOn(),
		);

		return $this->preparePresetsForOutput($provider->getPresets());
	}

	private function preparePresetsForOutput(array $presets, $defaultPresetKey = null): array
	{
		unset(
			$presets[Options::DEFAULT_FILTER],
			$presets[Options::TMP_FILTER]
		);

		if ($defaultPresetKey && isset($presets[$defaultPresetKey]))
		{
			$presets[$defaultPresetKey]['default'] = true;
		}

		return array_map(
			static fn($key) => [
				'id' => $key,
				'name' => $presets[$key]['name'],
				'fields' => ($presets[$key]['preparedFields'] ?? []),
				'default' => (bool)($presets[$key]['default'] ?? false),
			],
			array_keys($presets)
		);
	}

	/**
	 * @throws SqlQueryException
	 * @throws ObjectPropertyException
	 * @throws ArgumentException
	 * @throws SystemException
	 * @throws LoaderException
	 */
	private function loadProjects(
		array $filter,
		string $presetId,
		bool $useNavigation = true,
		bool $hasCollabersOnly = false,
	): array
	{
		$provider = new ProjectProvider(
			$this->userId,
			$this->mode,
			$this->mode === WorkgroupList::MODE_TASKS_PROJECT
				&& class_exists(Feature::class)
				&& Feature::isNewProjectsOn(),
		);

		$query = $provider->getPrimaryProjectsQuery(self::SELECT);
		$query = $provider->getQueryWithFilter($query, $filter, $presetId);
		if ($hasCollabersOnly)
		{
			$this->applyHasCollabersFilter($query);
		}

		$query->setOrder(self::ORDER);

		if ($useNavigation)
		{
			$query
				->setOffset($this->pageNavigation?->getOffset() ?? self::DEFAULT_OFFSET)
				->setLimit($this->pageNavigation?->getLimit() ?? self::DEFAULT_LIMIT)
			;
		}

		$projects = [];
		$result = $query->exec();
		while ($project = $result->fetch())
		{
			$projects[(int)$project['ID']] = $project;
		}

		if (empty($projects))
		{
			return [];
		}

		$projects = $provider->fillActions($projects);
		$projects = $provider->fillMembers($projects);
		$projects = $provider->fillCounters($projects);

		return $projects;
	}

	private function shouldFilterByCollabers(string $presetId): bool
	{
		return mb_strtolower(trim($presetId)) === self::PRESET_EXTRANET;
	}

	private function applyHasCollabersFilter(Query $query): void
	{
		$query->registerRuntimeField(
			new Reference(
				self::HAS_COLLABERS_ALIAS,
				CollabOptionTable::class,
				Join::on('this.ID', 'ref.COLLAB_ID')
					->where('ref.NAME', self::HAS_COLLABERS_OPTION_NAME)
					->where('ref.VALUE', 'Y'),
				['join_type' => 'INNER'],
			)
		);
	}

	private function buildResult(array $projects): array
	{
		if (empty($projects))
		{
			return $this->getEmptyResult();
		}

		return [
			'items' => $this->buildItems($projects),
			'groups' => $this->buildGroups($projects),
			'users' => $this->buildUsers($projects),
			'meta' => [
				'is_portal_projects_empty' => false,
			],
		];
	}

	private function buildItems(array $projects): array
	{
		$items = [];
		$hasCollabersMap = $this->getHasCollabersMap(array_keys($projects));

		foreach ($projects as $project)
		{
			$projectId = (int)$project['ID'];

			$items[] = new ProjectListItemDto(
				id: $projectId,
				activityDate: $this->resolveActivityDate($project['ACTIVITY_DATE'] ?? null),
				isPinned: ($project['IS_PINNED'] ?? 'N') === 'Y',
				opened: ($project['OPENED'] ?? 'N') === 'Y',
				closed: ($project['CLOSED'] ?? 'N') === 'Y',
				visible: ($project['VISIBLE'] ?? 'N') === 'Y',
				ownerId: $this->resolveOwnerId($project),
				moderatorIds: $this->resolveModeratorIds($project),
				memberIds: $this->resolveMemberIds($project),
				counter: $project['COUNTER'] ?? [],
				actions: $project['ACTIONS'] ?? [],
				hasCollabers: ($hasCollabersMap[$projectId] ?? false) === true,
			);
		}

		return $items;
	}

	private function buildGroups(array $projects): array
	{
		return GroupProvider::loadByIds(array_keys($projects));
	}

	private function getHasCollabersMap(array $projectIds): array
	{
		if (!class_exists(SocialnetworkProjectProvider::class))
		{
			return [];
		}

		return (new SocialnetworkProjectProvider())->ensureHasCollabersMap($projectIds);
	}

	private function buildUsers(array $projects): array
	{
		$userIds = [];

		foreach ($projects as $project)
		{
			$ownerId = $this->resolveOwnerId($project);
			if ($ownerId > 0)
			{
				$userIds[$ownerId] = $ownerId;
			}

			foreach ($this->resolveModeratorIds($project) as $moderatorId)
			{
				$userIds[$moderatorId] = $moderatorId;
			}

			foreach ($this->resolveMemberIds($project) as $memberId)
			{
				$userIds[$memberId] = $memberId;
			}
		}

		return empty($userIds)
			? []
			: UserRepository::getByIds(array_values($userIds));
	}

	private function resolveOwnerId(array $project): int
	{
		foreach (($project['MEMBERS']['HEADS'] ?? []) as $member)
		{
			if (($member['IS_OWNER'] ?? 'N') === 'Y')
			{
				return (int)($member['ID'] ?? 0);
			}
		}

		return 0;
	}

	private function resolveModeratorIds(array $project): array
	{
		$moderatorIds = [];

		foreach (($project['MEMBERS']['HEADS'] ?? []) as $member)
		{
			if (($member['IS_MODERATOR'] ?? 'N') === 'Y')
			{
				$userId = (int)($member['ID'] ?? 0);
				if ($userId > 0)
				{
					$moderatorIds[] = $userId;
				}
			}
		}

		return $moderatorIds;
	}

	private function resolveMemberIds(array $project): array
	{
		$memberIds = [];

		foreach (($project['MEMBERS']['MEMBERS'] ?? []) as $member)
		{
			if (($member['IS_ACCESS_REQUESTING'] ?? 'N') === 'Y')
			{
				continue;
			}

			$userId = (int)($member['ID'] ?? 0);
			if ($userId > 0)
			{
				$memberIds[] = $userId;
			}
		}

		return $memberIds;
	}

	private function resolveActivityDate(mixed $activityDate): ?int
	{
		if ($activityDate instanceof \DateTimeInterface)
		{
			return $activityDate->getTimestamp();
		}

		if (is_object($activityDate) && method_exists($activityDate, 'getTimestamp'))
		{
			return (int)$activityDate->getTimestamp();
		}

		if (is_numeric($activityDate))
		{
			return (int)$activityDate;
		}

		if (is_string($activityDate))
		{
			$timestamp = strtotime($activityDate);

			return ($timestamp === false ? null : $timestamp);
		}

		return null;
	}

	private function getEmptyResult(): array
	{
		return [
			'items' => [],
			'groups' => [],
			'users' => [],
			'meta' => [
				'is_portal_projects_empty' => $this->isPortalProjectsEmpty(),
			],
		];
	}

	private function isPortalProjectsEmpty(): bool
	{
		$row = WorkgroupTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=PROJECT' => 'Y',
			],
			'limit' => 1,
		])->fetch();

		return $row === false;
	}
}
