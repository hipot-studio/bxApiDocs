<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Section;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query as OrmQuery;
use Bitrix\Main\UI\Filter\NumberType;
use Bitrix\Socialnetwork\WorkgroupTable;
use Bitrix\Tasks\Internals\Task\Priority;
use Bitrix\Tasks\Internals\Task\Template\TemplateMemberTable;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Bitrix\Tasks\Util\User;
use Bitrix\Tasks\V2\Internal\Access\Registry\TemplateRegistry;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateRightService;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\Template\RepeatTill;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\ReplicateParamsMapper;
use Bitrix\Tasks\V2\Internal\Repository\Replication\ReplicationErrorRepository;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\List;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\Field;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Column\DataProvider\ReplicationProvider;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Action\ReplicationRowActions;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\ReplicationRows;

/**
 * Fetches the rule rows of the "Regular tasks" section slice and assembles DTO-S1 (P1.T3/P1.T4).
 *
 * Builds a query over the V2 template list via {@see List\QueryBuilder} with a role slice
 * ({@see \Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder\RoleFilterModifier})
 * and row visibility - the viewer's own rule that he may read
 * ({@see \Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder\MembershipVisibilityModifier}),
 * grid pagination, and returns the raw rows for {@see \Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\ReplicationRows}.
 *
 * Each row carries `ID` (the grid sets `data-id`) and DTO-S1 in the NAME column.
 * The recurrence condition and deadline wording are formed on the frontend from the DTO
 * fields (`regularityType`, `cadenceParams`) - the backend returns only data (P1.T4).
 */
class ReplicationRowService
{
	private const SLICE_CAP = 5000;
	private const MEMBER_COLUMN_LIMIT = 5;

	// Default error-flag window for a row (DTO-S1.errorFlag): the last month.
	// The exact, period-dependent indicator is updated by the frontend from DTO-S2 (API-S3).
	private const ERROR_FLAG_WINDOW = 30 * 86400;

	// Recurrence params the card model keeps one-based while the storage keeps them zero-based.
	private const CARD_ONE_BASED_PARAMS = ['yearlyMonth1', 'yearlyMonth2', 'yearlyWeekDay'];

	public function __construct(
		private readonly List\QueryBuilder $queryBuilder,
		private readonly ReplicateParamsMapper $replicateParamsMapper,
		private readonly TemplateRightService $templateRightService,
		private readonly ReplicationErrorRepository $errorRepository,
		private readonly UserRepositoryInterface $userRepository,
	)
	{
	}

	/**
	 * @return array{rows: array<int, array>, hasMore: bool}
	 */
	public function getRows(SectionListQuery $query): array
	{
		$select = new List\Select([
			Field::Id,
			Field::Title,
			Field::Priority,
			Field::ResponsibleId,
			Field::GroupId,
			Field::Deadline,
			Field::MatchWorkTime,
			Field::Replicate,
			Field::ReplicateParams,
			Field::ReplicateType,
			Field::TParamReplicationCount,
			// Author timezone for the finished-repeat predicate (ALG-F1), mirroring the
			// canonical replicator: TIMEZONE_OFFSET param else User::getTimeZoneOffset(CREATED_BY).
			Field::CreatedBy,
		]);
		$filter = new List\Filter($this->buildFilterTuples($query));
		$order = new List\Order($this->buildOrderTuples($query));

		// Pagination over DISTINCT templates. The visibility branch (MembershipVisibilityModifier)
		// and the role slice may add a 1:N JOIN (visibility rights, MEMBERS in select for
		// RESPONSIBLE_ID), which multiplies one template into several rows and breaks
		// LIMIT/OFFSET/hasMore. So we first take a page of unique IDs (the
		// QueryHelper::decompose strategy with fairLimit) and load the data with a separate query by ID.
		// limit + 1 lookahead - as in the Template\History precedent (detects "has more").
		$pageIds = $this->fetchPageTemplateIds($query, $filter, $order, $query->limit + 1, $query->offset);

		$hasMore = count($pageIds) > $query->limit;
		$pageIds = array_slice($pageIds, 0, $query->limit);

		// Warm the page template registry with a single query: removes per-row SQL from
		// isDeleted()/isRegular()/loadTemplate(), which hit rows while assembling the DTO and rights.
		TemplateRegistry::getInstance()->load($pageIds);

		// The "show the pencil" right is computed in a batch for the whole page (getTemplateRightsBatch
		// preloads the registry + rights itself), not per-row canSave. On the id-only after-model
		// canSave reduces to template_edit (the regular TemplateSaveRule branch is not executed:
		// isRegular()===false -> early return true), so we take exactly ACTION_TEMPLATE_EDIT.
		$editRights = $this->resolveEditRights($pageIds, $query->viewerId);
		// The remove right - a separate batch per page (after resolveEditRights,
		// [P1.T2]); it never reaches DTO-S1 and is stored in the service row key ROW_RIGHTS.
		$removeRights = $this->resolveRemoveRights($pageIds, $query->viewerId);

		$fetched = $this->fetchRowsByIds($select, $order, $pageIds);
		$errorFlags = $this->resolveErrorFlags($fetched);
		$visibleColumnIds = $this->normalizeVisibleColumnIds($query->visibleColumnIds);
		$memberIds = $this->resolveMemberIds($pageIds, $this->resolveVisibleMemberTypes($visibleColumnIds));
		$users = $this->resolveUsers(
			$fetched,
			$memberIds,
			$this->isColumnVisible($visibleColumnIds, ReplicationProvider::ORIGINATOR_NAME_COLUMN),
		);
		$projectColumnVisible = $this->isColumnVisible($visibleColumnIds, ReplicationProvider::GROUP_NAME_COLUMN);
		$projectNames = $projectColumnVisible ? $this->resolveProjectNames($fetched) : [];

		$rows = [];
		foreach ($fetched as $row)
		{
			$templateId = (int)($row['ID'] ?? 0);
			$assembledRow = $this->assembleRow(
				$row,
				$errorFlags[$templateId] ?? false,
				$editRights[$templateId] ?? false,
				$removeRights[$templateId] ?? false,
				$users,
				$memberIds[$templateId] ?? [],
				$projectNames,
				$projectColumnVisible,
			);
			$group = $this->buildGroupData($query->groupBy, $row);
			if ($group !== null)
			{
				$assembledRow[ReplicationRows::GROUP_DATA] = $group;
			}

			$rows[] = $assembledRow;
		}

		return ['rows' => $rows, 'hasMore' => $hasMore];
	}

	/**
	 * Current page IDs with the same visibility, filter and ordering as {@see getRows()}.
	 *
	 * Realtime uses this lightweight query to verify that an in-place row update cannot
	 * change page membership or ordering before requesting the full data of affected rows.
	 *
	 * @return int[]
	 */
	public function getPageTemplateIds(SectionListQuery $query): array
	{
		return $this->fetchPageTemplateIds(
			$query,
			new List\Filter($this->buildFilterTuples($query)),
			new List\Order($this->buildOrderTuples($query)),
			$query->limit,
			$query->offset,
		);
	}

	/**
	 * @return int[]
	 */
	private function fetchPageTemplateIds(
		SectionListQuery $sectionQuery,
		List\Filter $filter,
		List\Order $order,
		int $limit,
		int $offset,
	): array
	{
		$pageQuery = $this->queryBuilder->buildListQuery(
			new List\Select([Field::Id]),
			$filter,
			$order,
			$limit,
			$offset,
		);
		$this->applySpecialFilters($pageQuery, $sectionQuery);
		$pageQuery->setDistinct();

		$pageIds = [];
		foreach ($pageQuery->exec()->fetchAll() as $row)
		{
			$id = (int)($row['ID'] ?? 0);
			if ($id > 0)
			{
				$pageIds[] = $id;
			}
		}

		return $pageIds;
	}

	/**
	 * Exact number of rules in the slice - for the "Show count" action of the panel counter.
	 *
	 * Not computed with the page: paging works on the limit + 1 lookahead ({@see getRows()}),
	 * so the section costs one query per page and this COUNT only when the user asks for the
	 * total. Counting unique IDs, because the role slice may join a template to several member
	 * rows - the same reason the page is selected over DISTINCT IDs.
	 */
	public function getTotalCount(SectionListQuery $query): int
	{
		$countQuery = $this->queryBuilder->buildListQuery(
			new List\Select([Field::Id]),
			new List\Filter($this->buildFilterTuples($query)),
			new List\Order([]),
			0,
			0,
		);
		$this->applySpecialFilters($countQuery, $query);
		$countQuery->setSelect([new ExpressionField('CNT', 'COUNT(DISTINCT %s)', 'ID')]);

		return (int)($countQuery->exec()->fetch()['CNT'] ?? 0);
	}

	/**
	 * Row data by the already-selected page of IDs (the order follows $ids).
	 * The IDs already passed the visibility filter on the first pass, so the filter here is
	 * only by ID; a possible 1:N JOIN is collapsed by per-template deduplication.
	 *
	 * @param int[] $ids
	 * @return array<int, array>
	 */
	private function fetchRowsByIds(List\Select $select, List\Order $order, array $ids): array
	{
		if ($ids === [])
		{
			return [];
		}

		$dataQuery = $this->queryBuilder->buildListQuery(
			$select,
			new List\Filter([[Field::Id, 'in', $ids]]),
			$order,
			count($ids),
			0,
		);
		// The ID set is already bounded by the page size; we drop the LIMIT here, otherwise
		// the 1:N JOIN (MEMBERS / rights) would cut off some templates before deduplication.
		$dataQuery->setLimit(null);

		$byId = [];
		foreach ($dataQuery->exec()->fetchAll() as $row)
		{
			$id = (int)($row['ID'] ?? 0);
			if ($id > 0 && !isset($byId[$id]))
			{
				$byId[$id] = $row;
			}
		}

		// Preserve the page order (sorting was applied on the first pass).
		$ordered = [];
		foreach ($ids as $id)
		{
			if (isset($byId[$id]))
			{
				$ordered[] = $byId[$id];
			}
		}

		return $ordered;
	}

	/**
	 * All slice template ids (without pagination) - for the "Total" aggregate over the whole slice (P2.T4).
	 * The cap bounds the plan part on very large portals (a standard limit).
	 *
	 * @return int[]
	 */
	public function getSliceTemplateIds(SectionListQuery $query, int $cap = self::SLICE_CAP): array
	{
		$select = new List\Select([Field::Id]);
		$filter = new List\Filter($this->buildFilterTuples($query));
		$order = new List\Order([[Field::Id, 'ASC']]);

		$listQuery = $this->queryBuilder->buildListQuery($select, $filter, $order, $cap, 0);
		$this->applySpecialFilters($listQuery, $query);

		// One ID per template, despite a possible 1:N JOIN of visibility rights (see getRows()).
		$listQuery->setDistinct();

		$ids = [];
		foreach ($listQuery->exec()->fetchAll() as $row)
		{
			$ids[] = (int)($row['ID'] ?? 0);
		}

		return array_values(array_filter($ids));
	}

	public function containsTemplate(SectionListQuery $query, int $templateId): bool
	{
		if ($templateId <= 0)
		{
			return false;
		}

		$filterTuples = $this->buildFilterTuples($query);
		$filterTuples[] = [Field::Id, '=', $templateId];

		$listQuery = $this->queryBuilder->buildListQuery(
			new List\Select([Field::Id]),
			new List\Filter($filterTuples),
			new List\Order([[Field::Id, 'ASC']]),
			1,
			0,
		);
		$this->applySpecialFilters($listQuery, $query);
		$listQuery->setDistinct();

		return (int)($listQuery->exec()->fetch()['ID'] ?? 0) === $templateId;
	}

	/**
	 * @return array<int, array{0: Field, 1: string, 2: mixed}>
	 */
	private function buildFilterTuples(SectionListQuery $query): array
	{
		$tuples = [
			// Row visibility: the viewer's own rule that he may read (Q-1).
			[Field::ViewerId, '=', ['userId' => $query->viewerId]],
			// Only rules with configured recurrence and not zombie (Q-3).
			[Field::ReplicateParams, '!=', ''],
			[Field::Zombie, '!=', 'Y'],
		];

		if ($query->roleFilter !== null && $query->roleFilter !== '')
		{
			$tuples[] = [Field::RoleId, '=', ['userId' => $query->viewerId, 'role' => $query->roleFilter]];
		}

		// Substring search in the rule title (the toolbar's standard live-search).
		if ($query->search !== null && $query->search !== '')
		{
			$tuples[] = [Field::Title, 'like', '%' . $this->escapeLike($query->search) . '%'];
		}

		// null means all rules, true means active rules, false means paused rules.
		if ($query->replicateActive !== null)
		{
			$tuples[] = [Field::Replicate, '=', $query->replicateActive ? 'Y' : 'N'];
		}

		if ($query->templateIds !== [])
		{
			$tuples[] = [Field::Id, 'in', $query->templateIds];
		}

		$this->appendNumericFilter(
			$tuples,
			$query->filter,
			'PRIORITY',
			Field::Priority,
			[Priority::AVERAGE, Priority::HIGH],
		);
		$this->appendNumericFilter(
			$tuples,
			$query->filter,
			'RESPONSIBLE_ID',
			Field::ResponsibleId,
		);
		$this->appendNumericFilter(
			$tuples,
			$query->filter,
			'CREATED_BY',
			Field::CreatedBy,
		);
		$this->appendIdFilter($tuples, $query->filter);

		return $tuples;
	}

	/**
	 * @param array<int, array{0: Field, 1: string, 2: mixed}> $tuples
	 * @param int[]|null $allowedValues
	 */
	private function appendNumericFilter(
		array &$tuples,
		array $filter,
		string $filterField,
		Field $field,
		?array $allowedValues = null,
	): void
	{
		$values = $this->normalizePositiveIds($filter[$filterField] ?? null);
		if ($allowedValues !== null)
		{
			$values = array_values(array_intersect($values, $allowedValues));
		}

		if ($values === [])
		{
			return;
		}

		$tuples[] = [
			$field,
			count($values) > 1 ? 'in' : '=',
			count($values) === 1 ? reset($values) : $values,
		];
	}

	/**
	 * @param array<int, array{0: Field, 1: string, 2: mixed}> $tuples
	 */
	private function appendIdFilter(array &$tuples, array $filter): void
	{
		$ids = $this->normalizePositiveIds($filter['ID'] ?? null);
		if ($ids !== [])
		{
			$tuples[] = [
				Field::Id,
				count($ids) > 1 ? 'in' : '=',
				count($ids) === 1 ? reset($ids) : $ids,
			];

			return;
		}

		$from = $this->normalizePositiveId($filter['ID_from'] ?? null);
		$to = $this->normalizePositiveId($filter['ID_to'] ?? null);
		switch ($filter['ID_numsel'] ?? null)
		{
			case NumberType::SINGLE:
				$id = $from ?? $to;
				if ($id !== null)
				{
					$tuples[] = [Field::Id, '=', $id];
				}
				break;

			case NumberType::RANGE:
				if ($from !== null)
				{
					$tuples[] = [Field::Id, '>=', $from];
				}
				if ($to !== null)
				{
					$tuples[] = [Field::Id, '<=', $to];
				}
				break;

			case NumberType::MORE:
				if ($from !== null)
				{
					$tuples[] = [Field::Id, '>', $from];
				}
				break;

			case NumberType::LESS:
				if ($to !== null)
				{
					$tuples[] = [Field::Id, '<', $to];
				}
				break;
		}
	}

	private function applySpecialFilters(OrmQuery $query, SectionListQuery $sectionQuery): void
	{
		$filter = $sectionQuery->filter;

		$this->applyGroupFilter($query, $filter);
		$this->applyRegularityTypeFilter($query, $filter);
		$this->applyMemberFilter(
			$query,
			$filter,
			'AUDITOR',
			TemplateMemberTable::MEMBER_TYPE_AUDITOR,
		);
		$this->applyMemberFilter(
			$query,
			$filter,
			'ACCOMPLICE',
			TemplateMemberTable::MEMBER_TYPE_ACCOMPLICE,
		);
	}

	private function applyGroupFilter(OrmQuery $query, array $filter): void
	{
		$groupIds = $this->normalizePositiveIds($filter['GROUP_ID'] ?? null);
		if ($groupIds === [])
		{
			return;
		}

		$query->whereIn(Field::GroupId->value, $groupIds);
	}

	private function applyRegularityTypeFilter(OrmQuery $query, array $filter): void
	{
		if (!array_key_exists('REPLICATE_TYPE', $filter))
		{
			return;
		}

		$values = is_array($filter['REPLICATE_TYPE'])
			? $filter['REPLICATE_TYPE']
			: [$filter['REPLICATE_TYPE']]
		;
		$types = [];
		foreach ($values as $value)
		{
			if ($value === null || $value === '' || $value === false || !is_scalar($value))
			{
				continue;
			}

			$type = RegularityType::tryFrom((int)$value);
			if ($type !== null)
			{
				$types[$type->value] = $type->value;
			}
		}

		if ($types === [])
		{
			return;
		}

		$typeFilter = OrmQuery::filter()
			->logic('or')
			->whereIn(Field::ReplicateType->value, array_values($types))
		;
		if (isset($types[RegularityType::Schedule->value]))
		{
			$typeFilter->whereNull(Field::ReplicateType->value);
		}

		$query->where($typeFilter);
	}

	private function applyMemberFilter(
		OrmQuery $query,
		array $filter,
		string $filterField,
		string $memberType,
	): void
	{
		$userIds = $this->normalizePositiveIds($filter[$filterField] ?? null);
		if ($userIds === [])
		{
			return;
		}

		$subQuery = TemplateMemberTable::query()
			->setSelect([new ExpressionField('MEMBER_EXISTS', '1')])
			->where('TEMPLATE_ID', new SqlExpression('%s'))
			->where('TYPE', $memberType)
			->whereIn('USER_ID', $userIds)
		;

		$query->whereExpr("EXISTS({$subQuery->getQuery()})", [Field::Id->value]);
	}

	/**
	 * @return int[]
	 */
	private function normalizePositiveIds(mixed $value): array
	{
		$values = is_array($value) ? $value : [$value];
		$ids = [];
		foreach ($values as $item)
		{
			if (!is_scalar($item))
			{
				continue;
			}

			$id = (int)$item;
			if ($id > 0)
			{
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}

	private function normalizePositiveId(mixed $value): ?int
	{
		if (!is_scalar($value))
		{
			return null;
		}

		$id = (int)$value;

		return $id > 0 ? $id : null;
	}

	/**
	 * Escapes the LIKE special characters (`\`, `%`, `_`) so the search matches the substring
	 * literally instead of treating the input as a pattern. The `%...%` wrapper is added by the caller.
	 */
	private function escapeLike(string $value): string
	{
		return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
	}

	/**
	 * @return array<int, array{0: Field, 1: string}>
	 */
	private function buildOrderTuples(SectionListQuery $query): array
	{
		$settings = SectionSettings::createFromStored([
			'groupBy' => $query->groupBy,
			'sortBy' => $query->sortBy,
			'sortDirection' => $query->sortDirection,
		]);

		$tuples = [];
		$groupField = match ($settings->groupBy)
		{
			'project' => Field::SectionProjectOrder,
			default => null,
		};
		if ($groupField !== null)
		{
			// groups always go ascending: the chosen direction orders the rules, not the headers
			$tuples[] = [$groupField, 'ASC'];
		}

		$sortField = match ($settings->sortBy)
		{
			'name' => Field::SectionNameOrder,
			// the "Repeat condition" column has no own field: rules are ordered by the rank of
			// their regularity type (by schedule -> by deadline -> after completion)
			'regularity' => Field::SectionTypeOrder,
			'originator' => Field::SectionOriginatorOrder,
			'assignee' => Field::SectionAssigneeOrder,
			'priority' => Field::SectionPriorityOrder,
			// creation date == template id: rules have no own created field here
			'created' => Field::Id,
			default => Field::SectionDeadlineOrder,
		};
		$tuples[] = [$sortField, $this->toOrderDirection($settings->sortDirection)];

		// stable tail; already ordered by id when sorting by creation date
		if ($sortField !== Field::Id)
		{
			$tuples[] = [Field::Id, 'ASC'];
		}

		return $tuples;
	}

	/**
	 * Wire value of the setting -> ORM order keyword.
	 */
	private function toOrderDirection(string $sortDirection): string
	{
		return $sortDirection === 'desc' ? 'DESC' : 'ASC';
	}

	/**
	 * @return array{key: string, type: string, entityId: int|string|null, label: string}|null
	 */
	private function buildGroupData(string $groupBy, array $row): ?array
	{
		return match ($groupBy)
		{
			'project' => $this->buildProjectGroupData($row),
			default => null,
		};
	}

	/**
	 * @return array{key: string, type: string, entityId: int|null, label: string}
	 */
	private function buildProjectGroupData(array $row): array
	{
		$projectId = (int)($row['GROUP_ID'] ?? 0);
		if ($projectId <= 0)
		{
			return $this->makeGroupData(
				key: 'project:null',
				type: 'project',
				entityId: null,
				label: (string)Loc::getMessage('TASKS_V2_REPLICATION_GROUP_PROJECT_EMPTY'),
			);
		}

		$projectName = trim((string)($row['SECTION_PROJECT_NAME'] ?? ''));

		return $this->makeGroupData(
			key: "project:{$projectId}",
			type: 'project',
			entityId: $projectId,
			label: $projectName !== ''
				? $projectName
				: (string)Loc::getMessage('TASKS_V2_REPLICATION_GROUP_PROJECT_DELETED'),
		);
	}

	/**
	 * @return array{key: string, type: string, entityId: int|string|null, label: string}
	 */
	private function makeGroupData(
		string $key,
		string $type,
		int|string|null $entityId,
		string $label,
	): array
	{
		return [
			'key' => $key,
			'type' => $type,
			'entityId' => $entityId,
			'label' => $label,
		];
	}

	private function assembleRow(
		array $row,
		bool $errorFlag,
		bool $canEdit,
		bool $canRemove,
		Entity\UserCollection $users,
		array $memberIds,
		array $projectNames,
		bool $projectColumnVisible,
	): array
	{
		$templateId = (int)($row['ID'] ?? 0);
		$regularityType = RegularityType::fromValue($row['REPLICATE_TYPE'] ?? null);

		$storedParams = $this->extractParams($row['REPLICATE_PARAMS'] ?? null);
		$cadenceParams = $this->toCardParams($storedParams);

		$responsibleId = (int)($row['RESPONSIBLE_ID'] ?? 0);

		$dto = [
			'templateId' => $templateId,
			'name' => (string)($row['TITLE'] ?? ''),
			'isImportant' => (int)($row['PRIORITY'] ?? 0) === Priority::HIGH,
			'responsibleId' => $responsibleId,
			'responsible' => $this->buildResponsible($responsibleId, $users),
			'regularityType' => $regularityType->value,
			'cadenceParams' => $cadenceParams,
			// "Skip weekends": the deadline is stored in working time, the frontend classifies
			// the column label by the working day (like the recurrence panel by task.matchesWorkTime).
			'matchesWorkTime' => ($row['MATCH_WORK_TIME'] ?? 'N') === 'Y',
			'deadlineRule' => $regularityType === RegularityType::Deadline ? $cadenceParams : null,
			'repeatStatus' => $this->resolveRepeatStatus($row, $storedParams),
			'errorFlag' => $errorFlag,
			'rights' => [
				'canEdit' => $canEdit,
			],
		];

		return [
			'ID' => $templateId,
			ReplicationProvider::ORIGINATOR_NAME_COLUMN => $this->escapeColumnValue(
				$this->buildUserName((int)($row['CREATED_BY'] ?? 0), $users),
			),
			ReplicationProvider::GROUP_NAME_COLUMN => $this->buildProjectCell(
				$templateId,
				(int)($row['GROUP_ID'] ?? 0),
				$projectNames,
				$canEdit,
				$projectColumnVisible,
			),
			ReplicationProvider::AUDITOR_COLUMN => $this->buildMemberNames(
				$memberIds[TemplateMemberTable::MEMBER_TYPE_AUDITOR]['ids'] ?? [],
				$users,
				$memberIds[TemplateMemberTable::MEMBER_TYPE_AUDITOR]['overflow'] ?? false,
			),
			ReplicationProvider::ACCOMPLICE_COLUMN => $this->buildMemberNames(
				$memberIds[TemplateMemberTable::MEMBER_TYPE_ACCOMPLICE]['ids'] ?? [],
				$users,
				$memberIds[TemplateMemberTable::MEMBER_TYPE_ACCOMPLICE]['overflow'] ?? false,
			),
			ReplicationProvider::NAME_COLUMN => $dto,
			// The service row rights key (P1.T2): read by the "Delete" item via rawFields,
			// not rendered as a cell and never reaching TPL-S1 data-attributes.
			ReplicationRowActions::ROW_RIGHTS => [
				'canRemove' => $canRemove,
			],
		];
	}

	/**
	 * Responsible users of the page rows (name + avatar) in one batch query - without N+1.
	 * The frontend renders them in DTO-S1.responsible; without enrichment the cell always shows "As configured".
	 *
	 * @param array<int, array> $rows
	 */
	private function resolveUsers(array $rows, array $memberIds, bool $includeCreators): Entity\UserCollection
	{
		$ids = [];
		foreach ($rows as $row)
		{
			$responsibleId = (int)($row['RESPONSIBLE_ID'] ?? 0);
			if ($responsibleId > 0)
			{
				$ids[] = $responsibleId;
			}

			$creatorId = (int)($row['CREATED_BY'] ?? 0);
			if ($includeCreators && $creatorId > 0)
			{
				$ids[] = $creatorId;
			}
		}
		foreach ($memberIds as $membersByType)
		{
			foreach ($membersByType as $memberData)
			{
				array_push($ids, ...$memberData['ids']);
			}
		}

		if ($ids === [])
		{
			return new Entity\UserCollection();
		}

		return $this->userRepository->getByIds(array_values(array_unique($ids)));
	}

	/**
	 * @param int[] $templateIds
	 * @param string[] $memberTypes
	 * @return array<int, array<string, array{ids: int[], overflow: bool}>>
	 */
	private function resolveMemberIds(array $templateIds, array $memberTypes): array
	{
		if ($templateIds === [] || $memberTypes === [])
		{
			return [];
		}

		$result = [];
		$rows = $this->fetchLimitedMemberRows($templateIds, $memberTypes);
		foreach ($rows as $row)
		{
			$templateId = (int)($row['TEMPLATE_ID'] ?? 0);
			$userId = (int)($row['USER_ID'] ?? 0);
			$type = (string)($row['TYPE'] ?? '');
			if ($templateId > 0 && $userId > 0 && $type !== '')
			{
				$result[$templateId][$type] ??= [
					'ids' => [],
					'overflow' => false,
				];

				if (count($result[$templateId][$type]['ids']) < self::MEMBER_COLUMN_LIMIT)
				{
					$result[$templateId][$type]['ids'][] = $userId;
				}
				else
				{
					$result[$templateId][$type]['overflow'] = true;
				}
			}
		}

		return $result;
	}

	/**
	 * @param int[] $templateIds
	 * @param string[] $memberTypes
	 * @return array<int, array{ID: int|string, TEMPLATE_ID: int|string, USER_ID: int|string, TYPE: string}>
	 */
	private function fetchLimitedMemberRows(array $templateIds, array $memberTypes): array
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$table = $helper->quote(TemplateMemberTable::getTableName());
		$idColumn = $helper->quote('ID');
		$templateIdColumn = $helper->quote('TEMPLATE_ID');
		$userIdColumn = $helper->quote('USER_ID');
		$typeColumn = $helper->quote('TYPE');
		$limit = self::MEMBER_COLUMN_LIMIT + 1;

		$queries = [];
		foreach ($templateIds as $templateId)
		{
			$templateId = (int)$templateId;
			if ($templateId <= 0)
			{
				continue;
			}

			foreach ($memberTypes as $memberType)
			{
				$memberType = $helper->forSql($memberType);
				$query = "
					SELECT
						{$idColumn} AS {$idColumn},
						{$templateIdColumn} AS {$templateIdColumn},
						{$userIdColumn} AS {$userIdColumn},
						{$typeColumn} AS {$typeColumn}
					FROM {$table}
					WHERE {$templateIdColumn} = {$templateId}
						AND {$typeColumn} = '{$memberType}'
					ORDER BY {$idColumn} ASC
				";

				$queries[] = '(' . $helper->getTopSql($query, $limit) . ')';
			}
		}

		if ($queries === [])
		{
			return [];
		}

		$result = [];
		$queryResult = $connection->query(
			implode("\nUNION ALL\n", $queries)
			. "\nORDER BY {$templateIdColumn} ASC, {$typeColumn} ASC, {$idColumn} ASC"
		);
		while ($row = $queryResult->fetch())
		{
			$result[] = $row;
		}

		return $result;
	}

	/**
	 * @param array<string, true> $visibleColumnIds
	 * @return string[]
	 */
	private function resolveVisibleMemberTypes(array $visibleColumnIds): array
	{
		$types = [];
		if ($this->isColumnVisible($visibleColumnIds, ReplicationProvider::AUDITOR_COLUMN))
		{
			$types[] = TemplateMemberTable::MEMBER_TYPE_AUDITOR;
		}
		if ($this->isColumnVisible($visibleColumnIds, ReplicationProvider::ACCOMPLICE_COLUMN))
		{
			$types[] = TemplateMemberTable::MEMBER_TYPE_ACCOMPLICE;
		}

		return $types;
	}

	/**
	 * @param string[] $visibleColumnIds
	 * @return array<string, true>
	 */
	private function normalizeVisibleColumnIds(array $visibleColumnIds): array
	{
		$available = [
			ReplicationProvider::NAME_COLUMN,
			ReplicationProvider::ASSIGNEE_COLUMN,
			ReplicationProvider::CONDITION_COLUMN,
			ReplicationProvider::DEADLINE_COLUMN,
			ReplicationProvider::ORIGINATOR_NAME_COLUMN,
			ReplicationProvider::GROUP_NAME_COLUMN,
			ReplicationProvider::ID_COLUMN,
			ReplicationProvider::AUDITOR_COLUMN,
			ReplicationProvider::ACCOMPLICE_COLUMN,
		];

		$result = [];
		foreach ($visibleColumnIds as $columnId)
		{
			if (!is_string($columnId))
			{
				continue;
			}

			if (
				in_array($columnId, $available, true)
				|| str_starts_with($columnId, ReplicationProvider::PERIOD_COLUMN_PREFIX)
			)
			{
				$result[$columnId] = true;
			}
		}

		return $result;
	}

	/**
	 * @param array<string, true> $visibleColumnIds
	 */
	private function isColumnVisible(array $visibleColumnIds, string $columnId): bool
	{
		return isset($visibleColumnIds[$columnId]);
	}

	/**
	 * @param array<int, array> $rows
	 * @return array<int, string>
	 */
	private function resolveProjectNames(array $rows): array
	{
		$groupIds = [];
		foreach ($rows as $row)
		{
			$groupId = (int)($row['GROUP_ID'] ?? 0);
			if ($groupId > 0)
			{
				$groupIds[$groupId] = $groupId;
			}
		}

		if ($groupIds === [])
		{
			return [];
		}

		if (!Loader::includeModule('socialnetwork'))
		{
			return [];
		}

		$result = [];
		foreach (
			WorkgroupTable::query()
				->setSelect(['ID', 'NAME'])
				->whereIn('ID', array_values($groupIds))
				->exec()
				->fetchAll() as $group
		)
		{
			$result[(int)$group['ID']] = (string)$group['NAME'];
		}

		return $result;
	}

	private function buildUserName(int $userId, Entity\UserCollection $users): string
	{
		return $users->findOneById($userId)?->name ?? '';
	}

	/**
	 * @param int[] $userIds
	 */
	private function buildMemberNames(array $userIds, Entity\UserCollection $users, bool $overflow): string
	{
		$names = [];
		foreach ($userIds as $userId)
		{
			$name = $this->buildUserName($userId, $users);
			if ($name !== '')
			{
				$names[] = $name;
			}
		}
		if ($overflow)
		{
			$names[] = '...';
		}

		return $this->escapeColumnValue(implode(', ', $names));
	}

	/**
	 * Project cell: the name of the rule's project, and for a rule without one - the "+" the
	 * task list shows on row hover ({@see \Bitrix\Tasks\Grid\Task\Row\Content\Project}). The
	 * button assigns a project through the section's batch path
	 * ({@see \Bitrix\Tasks\V2\Internal\Service\Replication\Batch\BatchTemplateActionService::ACTION_MOVE_TO_PROJECT}),
	 * so it is offered only for a rule the viewer may edit; the batch rechecks the right anyway.
	 *
	 * @param array<int, string> $projectNames
	 */
	private function buildProjectCell(
		int $templateId,
		int $groupId,
		array $projectNames,
		bool $canEdit,
		bool $columnVisible,
	): string
	{
		$name = (string)($projectNames[$groupId] ?? '');
		if ($name !== '')
		{
			return $this->escapeColumnValue($name);
		}

		if (!$columnVisible || !$canEdit || $templateId <= 0)
		{
			return '';
		}

		$label = $this->escapeColumnValue((string)Loc::getMessage('TASKS_V2_REPLICATION_ROW_PROJECT_ADD'));
		$onClick = 'BX.PreventDefault(event); BX.Tasks.V2.Replication.openProjectSelector(' . $templateId . ', this)';

		return '<span class="tasks-replication-project">'
			. '<button type="button" class="tasks-replication-project__add"'
			. ' data-testid="replication-grid-project-add"'
			. ' aria-label="' . $label . '"'
			. ' aria-haspopup="dialog"'
			. ' aria-expanded="false"'
			. ' onclick="' . $onClick . '">'
			. '<span class="ui-icon-set --plus-m" aria-hidden="true"></span>'
			. '</button>'
			. '</span>'
		;
	}

	private function escapeColumnValue(string $value): string
	{
		return htmlspecialcharsbx($value);
	}

	/**
	 * @return array{id: int, name: string, avatar: ?string}|null null -> the frontend shows "As configured"
	 */
	private function buildResponsible(int $responsibleId, Entity\UserCollection $users): ?array
	{
		if ($responsibleId <= 0)
		{
			return null;
		}

		$user = $users->findOneById($responsibleId);
		if ($user === null)
		{
			return null;
		}

		return [
			'id' => $responsibleId,
			'name' => (string)$user->name,
			'avatar' => $user->image?->src,
		];
	}

	/**
	 * Error indicator for the page rows (DTO-S1.errorFlag) over the default window - in one
	 * grouped query (P5.T1). The exact, period-dependent indicator is updated by the frontend
	 * from DTO-S2 (API-S3).
	 *
	 * @param array<int, array> $rows
	 * @return array<int, bool> [templateId => errorFlag]
	 */
	private function resolveErrorFlags(array $rows): array
	{
		$templateIds = [];
		foreach ($rows as $row)
		{
			$templateIds[] = (int)($row['ID'] ?? 0);
		}

		$templateIds = array_values(array_filter($templateIds));
		if ($templateIds === [])
		{
			return [];
		}

		$now = time();

		return $this->errorRepository->hasErrorInPeriod($templateIds, $now - self::ERROR_FLAG_WINDOW, $now);
	}

	/**
	 * @return array<string, mixed> camelCase form of the recurrence params, exactly as stored
	 */
	private function extractParams(mixed $serialized): array
	{
		if (!is_string($serialized) || $serialized === '')
		{
			return [];
		}

		return $this->replicateParamsMapper->mapToValueObject($serialized)?->toArray() ?? [];
	}

	/**
	 * Recurrence params in the dialect of the card model, which the shared phrase generator
	 * ({@see \Bitrix\Tasks\V2\Internal\Service\Replication\Section\ReplicationRowService} -> DTO-S1 ->
	 * `tasks.v2.component.fields.replication` ReplicateRuleGenerator) reads: the storage keeps the
	 * yearly month and weekday zero-based, the card model one-based (mapReplicateParamsToModel in
	 * `tasks.v2.provider.service.task-service`). Without the shift the section names every month and
	 * weekday one position earlier - a November 5 date is rendered as October 5.
	 *
	 * @param array<string, mixed> $params camelCase form of the recurrence params, as stored
	 * @return array<string, mixed>
	 */
	private function toCardParams(array $params): array
	{
		foreach (self::CARD_ONE_BASED_PARAMS as $field)
		{
			if (isset($params[$field]))
			{
				$params[$field] = (int)$params[$field] + 1;
			}
		}

		return $params;
	}

	/**
	 * active / paused / finished (AC-S-066, AC-S-071; ALG-F1).
	 *
	 * `finished` overrides both active and paused: a rule is finished when its recurrence limit
	 * is exhausted by REPEAT_TILL (Q-2 - exhaustion, not the mere fact REPLICATE='N'). Otherwise -
	 * active/paused by REPLICATE.
	 *
	 * @param array<string, mixed> $params camelCase form of REPLICATE_PARAMS (already unpacked in assembleRow)
	 */
	private function resolveRepeatStatus(array $row, array $params): string
	{
		if ($this->isRepeatExhausted($params, (int)($row['TPARAM_REPLICATION_COUNT'] ?? 0), $row))
		{
			return 'finished';
		}

		return ($row['REPLICATE'] ?? '') === 'Y' ? 'active' : 'paused';
	}

	/**
	 * Whether the recurrence limit is exhausted by REPEAT_TILL - mirrors the replicator canon
	 * ({@see \Bitrix\Tasks\Replication\Template\Repetition\Time\Service\ExecutionService::getTemplateNextExecutionTime()}
	 * and {@see \Bitrix\Tasks\Replication\EventHandler\OnTaskCompleted\AfterCompletionReplicationHandler::isChainFinished()}),
	 * so that `finished` happens exactly when the replicator itself turns off REPLICATE.
	 *
	 * The canon works on a live template/agent (per-template SQL); the section slice is built in a
	 * batch over raw rows, so the predicate is reproduced here on the same data and in the same author
	 * timezone (TIMEZONE_OFFSET, otherwise the creator timezone). Consolidation into a shared helper is a
	 * recommended follow-up (see report M3): extraction requires refactoring the live replication path under the regular tasks release flags.
	 *
	 * @param array<string, mixed> $params camelCase form of REPLICATE_PARAMS
	 * @param array<string, mixed> $row raw row (for the author timezone)
	 */
	private function isRepeatExhausted(array $params, int $replicationCount, array $row): bool
	{
		$repeatTill = $params['repeatTill'] ?? null;

		if ($repeatTill === RepeatTill::TIMES->value)
		{
			$times = (int)($params['times'] ?? 0);

			return $times > 0 && $replicationCount >= $times;
		}

		if ($repeatTill === RepeatTill::DATE->value)
		{
			$endDate = (string)($params['endDate'] ?? '');
			$nextExecutionTime = (string)($params['nextExecutionTime'] ?? '');
			if ($endDate === '' || $nextExecutionTime === '')
			{
				return false;
			}

			$offset = $this->resolveCreatorTimeZoneOffset($params, $row);
			$endTs = (int)MakeTimeStamp($endDate) - $offset;
			$nextTs = (int)MakeTimeStamp($nextExecutionTime) - $offset;

			// Exhausted when the NEXT instance goes past the end date (not endDate < now()).
			return $endTs > 0 && $nextTs > 0 && $nextTs > $endTs;
		}

		// 'endless' | null -> no limit, never finished.
		return false;
	}

	/**
	 * The template author timezone for recurrence date arithmetic, as in the canon
	 * ({@see \Bitrix\Tasks\Replication\Template\Repetition\Time\Service\ExecutionService::assembleExecutionContext()}):
	 * new templates store TIMEZONE_OFFSET = 0 (time is already normalized to the server), so only
	 * legacy templates without the param take the creator's live timezone.
	 *
	 * @param array<string, mixed> $params camelCase form of REPLICATE_PARAMS
	 * @param array<string, mixed> $row raw row (CREATED_BY)
	 */
	private function resolveCreatorTimeZoneOffset(array $params, array $row): int
	{
		$timezoneOffset = $params['timezoneOffset'] ?? null;
		if ($timezoneOffset !== null)
		{
			return (int)$timezoneOffset;
		}

		return (int)User::getTimeZoneOffset((int)($row['CREATED_BY'] ?? 0));
	}

	/**
	 * Computes the edit right (the pencil) in a batch over the page templates with a single
	 * rule-engine run on a warmed registry - instead of per-row canSave (a fresh getByPrimary +
	 * rule-engine + getTemplatePermissions SQL + extranet policy for each row).
	 *
	 * Equivalent to the current canSave on the id-only after-model: there TemplateSaveRule reduces to
	 * ACTION_TEMPLATE_EDIT (the regular branch is not executed, isRegular()===false -> return true),
	 * and the parent and extranet-policy checks over empty added-members return true.
	 *
	 * @param int[] $templateIds
	 * @return array<int, bool> [templateId => canEdit]
	 */
	private function resolveEditRights(array $templateIds, int $viewerId): array
	{
		if ($templateIds === [])
		{
			return [];
		}

		$rights = $this->templateRightService->getTemplateRightsBatch(
			$viewerId,
			$templateIds,
			['edit' => ActionDictionary::TEMPLATE_ACTIONS['edit']],
		);

		$result = [];
		foreach ($rights as $templateId => $matrix)
		{
			$result[(int)$templateId] = (bool)($matrix['edit'] ?? false);
		}

		return $result;
	}

	/**
	 * Computes the remove right (ACTION_TEMPLATE_REMOVE) in a batch over the page templates with a
	 * single rule-engine run on a warmed registry - after {@see resolveEditRights()},
	 * without per-row canRemove. Used only for the visibility of the "Delete" item (P1.T2).
	 *
	 * @param int[] $templateIds
	 * @return array<int, bool> [templateId => canRemove]
	 */
	private function resolveRemoveRights(array $templateIds, int $viewerId): array
	{
		if ($templateIds === [])
		{
			return [];
		}

		$rights = $this->templateRightService->getTemplateRightsBatch(
			$viewerId,
			$templateIds,
			['remove' => ActionDictionary::TEMPLATE_ACTIONS['remove']],
		);

		$result = [];
		foreach ($rights as $templateId => $matrix)
		{
			$result[(int)$templateId] = (bool)($matrix['remove'] ?? false);
		}

		return $result;
	}
}
