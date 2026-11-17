<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Agenda;

use Bitrix\Tasks\Replication\Template\Time\Service\ScheduleSequenceService;
use Bitrix\Tasks\V2\Internal\Dto\Replication\AgendaItemDto;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Replication\InstanceFactRepository;
use Bitrix\Tasks\V2\Internal\Repository\Replication\ReplicationErrorRepository;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\ReplicationRowService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\SectionListQuery;

/**
 * Сборка agenda раздела «Регулярные задачи» — независимый хронологический хвост от
 * «сегодня» (Q-2, P3.T1). Три группы: today (факт + план + сбои на сегодня), past
 * (состоявшиеся назад + сбои), future (запланированные вперёд). Период графика на agenda
 * не влияет; ролевой фильтр и срез (видимость по членству) применяются.
 *
 * Факт несёт `completed` (завершён vs в работе, единый предикат {@see Status::COMPLETED})
 * и `assignee` (исполнитель, батч-разрешение без N+1). `error`-события подмешиваются в
 * past/today из syslog по времени. `statusFilter` режет поток до пагинации.
 *
 * For `after_completion`, the unknown future chain is not projected. Once a transition is armed,
 * its concrete NEXT_EXEC is displayed as the single planned item.
 * Пагинация курсорная: today/past — по общему ключу
 * (время+тип источника+id), future — по времени и id шаблона.
 */
class AgendaService
{
	public const GROUP_TODAY = 'today';
	public const GROUP_PAST = 'past';
	public const GROUP_FUTURE = 'future';

	public const FILTER_ALL = 'all';
	public const FILTER_COMPLETED = 'completed';
	public const FILTER_CREATED = 'created';
	public const FILTER_PLANNED = 'planned';
	public const FILTER_ERROR = 'error';

	private const DEFAULT_LIMIT = 20;
	private const FUTURE_WINDOW_DAYS = 366;
	private const DAY_SECONDS = 86400;
	private const CURSOR_TYPE_ERROR = 0;
	private const CURSOR_TYPE_CREATED = 1;
	private const CURSOR_TYPE_PLAN = 2;

	private readonly AgendaPlanService $planService;

	public function __construct(
		private readonly ReplicationRowService $rowService,
		private readonly InstanceFactRepository $instanceFactRepository,
		ScheduleSequenceService $sequenceService,
		private readonly UserRepositoryInterface $userRepository,
		private readonly ReplicationErrorRepository $errorRepository = new ReplicationErrorRepository(),
		private readonly AgendaTemplateRepository $templateRepository = new AgendaTemplateRepository(),
		private readonly AgendaTodayFactRepository $todayFactRepository = new AgendaTodayFactRepository(),
		?AgendaPlanService $planService = null,
	)
	{
		$this->planService = $planService ?? new AgendaPlanService($sequenceService);
	}

	/**
	 * `templateId` сворачивает scope к одному шаблону (hover-попап/карточка), пройдя ту же
	 * проверку видимости, что и секционный срез: недоступный шаблон даёт пустой результат.
	 * `from`/`to` ограничивают окна всех групп (unix, пояс просматривающего). `statusFilter`
	 * режет поток ДО пагинации (P2.T2). `counts` — агрегат по scope+окну по ВСЕМ
	 * статусам (независимо от `statusFilter`), возвращается для запроса без курсора.
	 *
	 * @return array{
	 *   groups: array{today: array[], past: array[], future: array[]},
	 *   cursor: array{today: ?string, past: ?string, future: ?string},
	 *   counts?: array{completed: int, created: int, planned: int, error: int, total: int}
	 * }
	 */
	public function getTail(
		int $viewerId,
		?string $roleFilter,
		?bool $replicateActive,
		int $tzOffset,
		?string $group = null,
		?string $cursor = null,
		int $limit = self::DEFAULT_LIMIT,
		?int $templateId = null,
		?int $from = null,
		?int $to = null,
		string $statusFilter = self::FILTER_ALL,
		?string $search = null,
		array $filter = [],
	): array
	{
		$withCounts = $cursor === null;
		$sliceIds = $this->resolveScope($viewerId, $roleFilter, $replicateActive, $templateId, $search, $filter);

		if ($sliceIds === [])
		{
			return $this->emptyResult($withCounts);
		}

		$limit = $limit > 0 ? min($limit, 100) : self::DEFAULT_LIMIT;
		[$todayStart, $todayEnd] = $this->todayWindow($tzOffset);
		$now = time();
		$templates = $this->templateRepository->getPlannableByIds($sliceIds);
		$templateNames = $this->templateRepository->getNamesByIds($sliceIds);

		$groups = ['today' => [], 'past' => [], 'future' => []];
		$cursors = ['today' => null, 'past' => null, 'future' => null];

		$todayCursor = $group === self::GROUP_TODAY ? $this->decodeCursor($cursor) : null;
		$futureCursor = $group === self::GROUP_FUTURE ? $this->decodeCursor($cursor) : null;

		$todayFrom = $from !== null ? max($todayStart, $from) : $todayStart;
		$todayTo = $to !== null ? min($todayEnd, $to) : $todayEnd;
		$futureFrom = $from !== null ? max($todayEnd + 1, $from) : $todayEnd + 1;
		if ($futureCursor !== null)
		{
			$futureFrom = max($futureFrom, $futureCursor['ts']);
		}
		$futureTo = min(
			$to ?? PHP_INT_MAX,
			$futureFrom + self::FUTURE_WINDOW_DAYS * self::DAY_SECONDS,
		);

		$todayPlanPage = null;
		if (
			($group === null || $group === self::GROUP_TODAY)
			&& $this->includesPlan($statusFilter)
			&& $todayTo >= $todayFrom
		)
		{
			$todayPlanPage = [
				'from' => max($todayFrom, $now),
				'to' => $todayTo,
				'limit' => $limit + 1,
				'cursor' => $this->cursorForSource($todayCursor, self::CURSOR_TYPE_PLAN),
			];
		}

		$futurePlanPage = null;
		if (
			($group === null || $group === self::GROUP_FUTURE)
			&& $this->includesPlan($statusFilter)
			&& $futureTo >= $futureFrom
		)
		{
			$futurePlanPage = [
				'from' => $futureFrom,
				'to' => $futureTo,
				'limit' => $limit + 1,
				'cursor' => $futureCursor,
			];
		}

		$plan = $this->planService->build(
			$templates,
			$withCounts ? max($from ?? $now, $now) : null,
			$withCounts ? ($to ?? $todayEnd + self::FUTURE_WINDOW_DAYS * self::DAY_SECONDS) : null,
			$todayPlanPage,
			$futurePlanPage,
		);

		// Первый запрос (группа не указана) — все три группы; догрузка — только запрошенная.
		if ($group === null || $group === self::GROUP_TODAY)
		{
			[$items, $nextCursor] = $this->buildToday(
				$viewerId,
				$sliceIds,
				$todayStart,
				$todayEnd,
				$from,
				$to,
				$statusFilter,
				$todayCursor,
				$limit,
				$plan['today'],
				$templateNames,
			);
			$groups['today'] = $items;
			$cursors['today'] = $nextCursor;
		}

		if ($group === null || $group === self::GROUP_PAST)
		{
			[$items, $nextCursor] = $this->buildPast(
				$viewerId,
				$sliceIds,
				$todayStart,
				$limit,
				$group === self::GROUP_PAST ? $cursor : null,
				$from,
				$to,
				$statusFilter,
				$templateNames,
			);
			$groups['past'] = $items;
			$cursors['past'] = $nextCursor;
		}

		if ($group === null || $group === self::GROUP_FUTURE)
		{
			[$items, $nextCursor] = $this->buildFuture(
				$plan['future'],
				$limit,
				$statusFilter,
				$futureTo,
				$to,
				$futurePlanPage !== null
					&& ($to === null || $futureTo < $to)
					&& $this->planService->canContinueAfter($templates, $futureTo),
			);
			$groups['future'] = $items;
			$cursors['future'] = $nextCursor;
		}

		$result = [
			'groups' => $groups,
			'cursor' => $cursors,
		];

		if ($withCounts)
		{
			$result['counts'] = $this->buildCounts(
				$viewerId,
				$sliceIds,
				$todayStart,
				$todayEnd,
				$from,
				$to,
				$plan['count'],
			);
		}

		return $result;
	}

	/**
	 * Scope выборки: один видимый шаблон (`templateId != null`) или весь секционный срез.
	 * Единственный источник видимости — {@see ReplicationRowService::getSliceTemplateIds()}:
	 * `templateId`, не попавший в срез (нет членства/ACL или отсеян ролью), сворачивается в
	 * пустой scope — не обход прав.
	 *
	 * @return int[]
	 */
	private function resolveScope(
		int $viewerId,
		?string $roleFilter,
		?bool $replicateActive,
		?int $templateId,
		?string $search,
		array $filter,
	): array
	{
		if ($templateId !== null)
		{
			// A single template is asked for by a caller that already has its row in front of it
			// (hover popover, template card), and the section filters do not travel with that
			// request. Narrowing the scope by them would hide the history of a rule whose
			// recurrence is paused; visibility stays the only gate.
			$templateQuery = new SectionListQuery(viewerId: $viewerId, replicateActive: null);

			return $this->rowService->containsTemplate($templateQuery, $templateId) ? [$templateId] : [];
		}

		$search = trim((string)$search);

		return $this->rowService->getSliceTemplateIds(new SectionListQuery(
			viewerId: $viewerId,
			roleFilter: $roleFilter,
			replicateActive: $replicateActive,
			search: $search !== '' ? $search : null,
			filter: $filter,
		));
	}

	/**
	 * @param int[] $sliceIds
	 * @param array<int, array{when:int,templateId:int,name:string}> $planned
	 * @param array<int, string> $templateNames
	 * @return array{0:array[],1:?string}
	 */
	private function buildToday(
		int $viewerId,
		array $sliceIds,
		int $todayStart,
		int $todayEnd,
		?int $windowFrom,
		?int $windowTo,
		string $statusFilter,
		?array $cursor,
		int $limit,
		array $planned,
		array $templateNames,
	): array
	{
		// «Сегодня» подрезано окном: нижняя граница — не раньше from, верхняя — не позже to.
		$factFrom = $windowFrom !== null ? max($todayStart, $windowFrom) : $todayStart;
		$factTo = $windowTo !== null ? min($todayEnd, $windowTo) : $todayEnd;

		$items = [];

		if ($factTo >= $factFrom && $this->includesFact($statusFilter))
		{
			$instances = $this->todayFactRepository->getInstances(
				$viewerId,
				$sliceIds,
				$factFrom,
				$factTo,
				$limit + 1,
				$this->cursorForSource($cursor, self::CURSOR_TYPE_CREATED),
				$this->factCompletedFilter($statusFilter),
			);
			foreach ($this->factItems($instances) as $index => $item)
			{
				$item['_cursorType'] = self::CURSOR_TYPE_CREATED;
				$item['_cursorId'] = (int)$instances[$index]['taskId'];
				$items[] = $item;
			}
		}

		if ($factTo >= $factFrom && $this->includesPlan($statusFilter))
		{
			foreach ($planned as $plan)
			{
				$item = (new AgendaItemDto(
					when: $plan['when'],
					templateId: $plan['templateId'],
					name: $plan['name'],
					status: AgendaItemDto::STATUS_PLAN,
				))->toArray();
				$item['_cursorType'] = self::CURSOR_TYPE_PLAN;
				$item['_cursorId'] = $plan['templateId'];
				$items[] = $item;
			}
		}

		if ($factTo >= $factFrom && $this->includesError($statusFilter))
		{
			foreach (
				$this->errorItems(
					$sliceIds,
					$factFrom,
					$factTo,
					$templateNames,
					$this->cursorForSource($cursor, self::CURSOR_TYPE_ERROR),
					$limit + 1,
				)
				as $item
			)
			{
				$items[] = $item;
			}
		}

		$this->sortByCursorKey($items);
		$hasMore = count($items) > $limit;
		$items = array_slice($items, 0, $limit);

		$nextCursor = null;
		if ($hasMore && $items !== [])
		{
			$last = end($items);
			$nextCursor = $this->encodeCursor([
				'ts' => (int)$last['when'],
				'id' => (int)$last['_cursorId'],
				'type' => (int)$last['_cursorType'],
			]);
		}

		return [$this->stripCursorMetadata($items), $nextCursor];
	}

	/**
	 * Прошлое: общий ограниченный поток фактов и ошибок. Оба источника читаются по
	 * `limit + 1`, затем сливаются по стабильному ключу (время, тип источника, id).
	 * Тип источника в непрозрачном курсоре не смешивает пространства id задач и syslog.
	 *
	 * @param int[] $sliceIds
	 * @return array{0: array[], 1: ?string}
	 */
	private function buildPast(
		int $viewerId,
		array $sliceIds,
		int $todayStart,
		int $limit,
		?string $cursor,
		?int $windowFrom,
		?int $windowTo,
		string $statusFilter,
		array $templateNames,
	): array
	{
		$cursorData = $this->decodeCursor($cursor);
		// Верхняя граница окна не выходит за «сегодня»: заданный windowTo сужает её ещё сильнее.
		$to = $windowTo !== null ? min($todayStart - 1, $windowTo) : $todayStart - 1;
		$from = $windowFrom ?? 0;

		if ($to < $from)
		{
			return [[], null];
		}

		$items = [];

		if ($this->includesFact($statusFilter))
		{
			$instances = $this->instanceFactRepository->getInstances(
				$viewerId,
				$sliceIds,
				$from,
				$to,
				$limit + 1,
				$this->cursorForSource($cursorData, self::CURSOR_TYPE_CREATED),
				$this->factCompletedFilter($statusFilter),
			);

			foreach ($this->factItems($instances) as $index => $item)
			{
				$item['_cursorType'] = self::CURSOR_TYPE_CREATED;
				$item['_cursorId'] = (int)$instances[$index]['taskId'];
				$items[] = $item;
			}
		}

		if ($this->includesError($statusFilter))
		{
			$errors = $this->errorRepository->getErrorsBefore(
				$sliceIds,
				$from,
				$to,
				$this->cursorForSource($cursorData, self::CURSOR_TYPE_ERROR),
				$limit + 1,
			);
			foreach ($this->mapErrorItems($errors, $templateNames) as $item)
			{
				$items[] = $item;
			}
		}

		$this->sortByCursorKeyDescending($items);
		$hasMore = count($items) > $limit;
		$items = array_slice($items, 0, $limit);

		$nextCursor = null;
		if ($hasMore && $items !== [])
		{
			$last = end($items);
			$nextCursor = $this->encodeCursor([
				'ts' => (int)$last['when'],
				'id' => (int)$last['_cursorId'],
				'type' => (int)$last['_cursorType'],
			]);
		}

		return [$this->stripCursorMetadata($items), $nextCursor];
	}

	/**
	 * Будущее — только план (фактов/ошибок в будущем нет, AC-S-070). Фильтр, исключающий план
	 * (`completed`/`created`/`error`), даёт пустую future-группу.
	 *
	 * @param array<int, array{when:int,templateId:int,name:string}> $planned
	 * @return array{0: array[], 1: ?string}
	 */
	private function buildFuture(
		array $planned,
		int $limit,
		string $statusFilter,
		int $windowTo,
		?int $requestedTo,
		bool $canContinue,
	): array
	{
		if (!$this->includesPlan($statusFilter))
		{
			return [[], null];
		}

		$hasMore = count($planned) > $limit;
		$planned = array_slice($planned, 0, $limit);

		$items = [];
		foreach ($planned as $plan)
		{
			$items[] = (new AgendaItemDto(
				when: $plan['when'],
				templateId: $plan['templateId'],
				name: $plan['name'],
				status: AgendaItemDto::STATUS_PLAN,
			))->toArray();
		}

		$nextCursor = null;
		if ($hasMore && $planned !== [])
		{
			$last = end($planned);
			$nextCursor = $this->encodeCursor([
				'ts' => $last['when'],
				'id' => $last['templateId'],
				'type' => self::CURSOR_TYPE_PLAN,
			]);
		}
		elseif ($canContinue && ($requestedTo === null || $windowTo < $requestedTo))
		{
			$nextCursor = $this->encodeCursor([
				'ts' => $windowTo,
				'id' => PHP_INT_MAX,
				'type' => self::CURSOR_TYPE_PLAN,
			]);
		}

		return [$items, $nextCursor];
	}

	/**
	 * Факт-элементы страницы с завершённостью и исполнителем. Исполнители страницы разрешаются
	 * ОДНИМ батч-запросом (без N+1), как {@see ReplicationRowService::resolveResponsibleUsers()}.
	 *
	 * @param array<int, array{taskId:int, templateId:int, name:string, when:int, completed:bool, responsibleId:int}> $instances
	 * @return array[]
	 */
	private function factItems(array $instances): array
	{
		$users = $this->resolveAssignees($instances);

		$items = [];
		foreach ($instances as $inst)
		{
			$items[] = (new AgendaItemDto(
				when: $inst['when'],
				templateId: $inst['templateId'],
				name: $inst['name'],
				status: AgendaItemDto::STATUS_CREATED,
				taskId: $inst['taskId'],
				completed: $inst['completed'],
				assignee: $this->buildAssignee((int)$inst['responsibleId'], $users),
			))->toArray();
		}

		return $items;
	}

	/**
	 * Error-события окна как элементы `status=error` (P2.T3): имя — из уже загруженных шаблонов
	 * среза, `taskId`/`completed`/`assignee` — null.
	 *
	 * @param int[] $sliceIds
	 * @return array[]
	 */
	private function errorItems(
		array $sliceIds,
		int $from,
		int $to,
		array $templateNames,
		?array $cursor = null,
		int $limit = 1,
	): array
	{
		if ($to < $from)
		{
			return [];
		}

		$errors = $this->errorRepository->getErrors($sliceIds, $from, $to, $cursor, $limit);
		if ($errors === [])
		{
			return [];
		}

		return $this->mapErrorItems($errors, $templateNames);
	}

	/**
	 * @param array<int, array{errorId:int,templateId:int,when:int}> $errors
	 * @return array[]
	 */
	private function mapErrorItems(array $errors, array $templateNames): array
	{
		$items = [];
		foreach ($errors as $error)
		{
			$templateId = (int)$error['templateId'];
			$item = (new AgendaItemDto(
				when: (int)$error['when'],
				templateId: $templateId,
				name: $templateNames[$templateId] ?? '',
				status: AgendaItemDto::STATUS_ERROR,
			))->toArray();
			$item['_cursorType'] = self::CURSOR_TYPE_ERROR;
			$item['_cursorId'] = (int)($error['errorId'] ?? 0);
			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Стабильная сортировка смешанного потока по времени: при равных `when` порядок
	 * стабилизируется вторичным ключом (taskId для факта, иначе 0) — чтобы страница не «дрожала».
	 *
	 * @param array[] $items
	 */
	private function sortByWhen(array &$items): void
	{
		usort(
			$items,
			static fn (array $a, array $b): int
				=> ($a['when'] <=> $b['when']) ?: (($a['taskId'] ?? 0) <=> ($b['taskId'] ?? 0)),
		);
	}

	/**
	 * @param array[] $items
	 */
	private function sortByCursorKey(array &$items): void
	{
		usort(
			$items,
			static fn (array $a, array $b): int
				=> [$a['when'], $a['_cursorType'], $a['_cursorId']]
					<=> [$b['when'], $b['_cursorType'], $b['_cursorId']],
		);
	}

	/**
	 * @param array[] $items
	 */
	private function sortByCursorKeyDescending(array &$items): void
	{
		usort(
			$items,
			static fn (array $a, array $b): int
				=> [$b['when'], $b['_cursorType'], $b['_cursorId']]
					<=> [$a['when'], $a['_cursorType'], $a['_cursorId']],
		);
	}

	/**
	 * @param array[] $items
	 * @return array[]
	 */
	private function stripCursorMetadata(array $items): array
	{
		foreach ($items as &$item)
		{
			unset($item['_cursorType'], $item['_cursorId']);
		}
		unset($item);

		return $items;
	}

	/**
	 * Батч-разрешение исполнителей страницы одним запросом (без N+1).
	 *
	 * @param array<int, array{responsibleId:int}> $instances
	 */
	private function resolveAssignees(array $instances): Entity\UserCollection
	{
		$ids = [];
		foreach ($instances as $inst)
		{
			$responsibleId = (int)($inst['responsibleId'] ?? 0);
			if ($responsibleId > 0)
			{
				$ids[] = $responsibleId;
			}
		}

		if ($ids === [])
		{
			return new Entity\UserCollection();
		}

		return $this->userRepository->getByIds(array_values(array_unique($ids)));
	}

	/**
	 * @return array{id: int, name: string, avatar: ?string}|null
	 */
	private function buildAssignee(int $responsibleId, Entity\UserCollection $users): ?array
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
	 * SQL-фильтр завершённости факта по statusFilter: `completed` → только завершённые,
	 * `created` → только в работе, иначе (`all`) — без фильтра. Планово-/ошибочные фильтры сюда
	 * не доходят (факт для них не выбирается — {@see self::includesFact()}).
	 */
	private function factCompletedFilter(string $statusFilter): ?bool
	{
		return match ($statusFilter)
		{
			self::FILTER_COMPLETED => true,
			self::FILTER_CREATED => false,
			default => null,
		};
	}

	private function includesFact(string $statusFilter): bool
	{
		return in_array($statusFilter, [self::FILTER_ALL, self::FILTER_COMPLETED, self::FILTER_CREATED], true);
	}

	private function includesPlan(string $statusFilter): bool
	{
		return $statusFilter === self::FILTER_ALL || $statusFilter === self::FILTER_PLANNED;
	}

	private function includesError(string $statusFilter): bool
	{
		return $statusFilter === self::FILTER_ALL || $statusFilter === self::FILTER_ERROR;
	}

	/**
	 * Лёгкий агрегат по scope+окну (ADR § 6): индексный COUNT факта с разбивкой по
	 * завершённости, точный счётчик плана и индексный COUNT ошибок. Считается по всем статусам (all),
	 * независимо от активного statusFilter. Событийный тип наперёд не проецируется (AC-S-070).
	 *
	 * @param int[] $sliceIds
	 * @return array{completed: int, created: int, planned: int, error: int, total: int}
	 */
	private function buildCounts(
		int $viewerId,
		array $sliceIds,
		int $todayStart,
		int $todayEnd,
		?int $windowFrom,
		?int $windowTo,
		int $planned,
	): array
	{
		// Факт/ошибки — прошлое и сегодня: верхняя граница окна не выходит за «сегодня».
		$factFrom = $windowFrom ?? 0;
		$factTo = $windowTo !== null ? min($windowTo, $todayEnd) : $todayEnd;

		$fact = $this->instanceFactRepository->countCompletedAndTotal(
			$viewerId,
			$sliceIds,
			$factFrom,
			$factTo,
		);
		$completed = $fact['completed'];
		$created = max(0, $fact['total'] - $completed);

		$error = array_sum($this->errorRepository->countErrorsInPeriod($sliceIds, $factFrom, $factTo));

		return [
			'completed' => $completed,
			'created' => $created,
			'planned' => $planned,
			'error' => $error,
			'total' => $completed + $created + $planned,
		];
	}

	/**
	 * @return array{0: int, 1: int} [todayStart, todayEnd] серверные timestamps границ «сегодня» в поясе просматривающего
	 */
	private function todayWindow(int $tzOffset): array
	{
		$localNow = time() + $tzOffset;
		$localMidnight = (int)gmmktime(0, 0, 0, (int)gmdate('n', $localNow), (int)gmdate('j', $localNow), (int)gmdate('Y', $localNow));

		$todayStart = $localMidnight - $tzOffset;
		$todayEnd = $todayStart + self::DAY_SECONDS - 1;

		return [$todayStart, $todayEnd];
	}

	/**
	 * @return array{
	 *   groups: array{today: array[], past: array[], future: array[]},
	 *   cursor: array{today: ?string, past: ?string, future: ?string},
	 *   counts?: array{completed: int, created: int, planned: int, error: int, total: int}
	 * }
	 */
	private function emptyResult(bool $withCounts): array
	{
		$result = [
			'groups' => ['today' => [], 'past' => [], 'future' => []],
			'cursor' => ['today' => null, 'past' => null, 'future' => null],
		];
		if ($withCounts)
		{
			$result['counts'] = ['completed' => 0, 'created' => 0, 'planned' => 0, 'error' => 0, 'total' => 0];
		}

		return $result;
	}

	/**
	 * @return array{ts:int, id:int, type?:int}|null
	 */
	private function decodeCursor(?string $cursor): ?array
	{
		if ($cursor === null || $cursor === '')
		{
			return null;
		}

		$decoded = json_decode(base64_decode($cursor, true) ?: '', true);
		if (!is_array($decoded) || !isset($decoded['ts']))
		{
			return null;
		}

		$result = ['ts' => (int)$decoded['ts'], 'id' => (int)($decoded['id'] ?? 0)];
		if (isset($decoded['type']))
		{
			$result['type'] = (int)$decoded['type'];
		}

		return $result;
	}

	/**
	 * @param array{ts:int, id:int, type?:int} $data
	 */
	private function encodeCursor(array $data): string
	{
		$cursor = ['ts' => (int)$data['ts'], 'id' => (int)$data['id']];
		if (isset($data['type']))
		{
			$cursor['type'] = (int)$data['type'];
		}

		return base64_encode(json_encode($cursor));
	}

	/**
	 * @param array{ts:int,id:int,type?:int}|null $cursor
	 * @return array{ts:int,id:int}|null
	 */
	private function cursorForSource(?array $cursor, int $sourceType): ?array
	{
		if ($cursor === null)
		{
			return null;
		}

		$cursorType = (int)($cursor['type'] ?? self::CURSOR_TYPE_CREATED);
		$cursorId = match (true)
		{
			$cursorType < $sourceType => 0,
			$cursorType > $sourceType => PHP_INT_MAX,
			default => $cursor['id'],
		};

		return ['ts' => $cursor['ts'], 'id' => $cursorId];
	}
}
