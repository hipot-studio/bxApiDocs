<?php

namespace Bitrix\Sign\Service\Process;

use Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable;
use Bitrix\Bizproc\Workflow\Task\TaskUserTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Crm\Relation\EntityRelationTable;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\Permission\PermissionDictionary;
use Bitrix\Sign\Item;
use Bitrix\Sign\Item\Process\Launch;
use Bitrix\Sign\Item\Process\LaunchGroup;
use Bitrix\Sign\Item\Process\LaunchItem;
use Bitrix\Sign\Item\Process\ProcessDocument;
use Bitrix\Sign\Item\Process\ProcessOrigin;
use Bitrix\Sign\Item\Process\ProcessPage;
use Bitrix\Sign\Item\Process\ProcessParticipant;
use Bitrix\Sign\Item\Process\RunningProcess;
use Bitrix\Sign\Operation\CheckDocumentAccess;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Repository\Process\LaunchRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\Crm\ProcessTypeService;
use Bitrix\Sign\Integration\CRM\Entity;
use Bitrix\Sign\Type\Document\EntityType;
use Bitrix\Sign\Type\Integration\Rest\RestDocumentStatus;
use Bitrix\Sign\Type\Process\DocumentsState;
use Bitrix\Sign\Type\Process\LaunchItemStatus;
use Bitrix\Sign\Type\Process\RunningProcessFilterState;
use Bitrix\Sign\Type\Process\RunningProcessStatus;

Loc::loadMessages(__FILE__);

/**
 * The processes of the KEDO section that have already been started. The module keeps no states of processes
 * of its own: a row takes its stage, its last activity and its participants from the engine on every request,
 * and the only state that belongs here is "starting" - a row of a group whose process the queue has not
 * reached yet.
 *
 * The list is built on the launch groups and not on the states of the engine. A row of the list is a row of
 * a group, and the top level of the list is the group itself, so a process of the same type that was started
 * outside of KEDO has no group to belong to and does not appear here.
 *
 * Visibility is decided per row by the engine ({@see \CBPDocument::canUserOperateDocument()}): there is no
 * ready API of bizproc for "the started processes of a type of document with the rights taken into account",
 * and a model of rights of our own is exactly what must not appear. Because of that the selection of the tab
 * is not the selection of "My processes" of the section of business processes, which goes by participation
 * of the user: a personnel officer sees here the launches of their colleagues as well, which is what the tab
 * exists for.
 */
class RunningProcessService
{
	public const PAGE_SIZE = 20;

	/**
	 * A document the person asking has no business hearing about. A document that is not there and a
	 * document of somebody else are answered by this one code on purpose: the identifier comes from the
	 * client, and telling the two apart would say whose documents exist.
	 */
	public const ERROR_DOCUMENT_NOT_AVAILABLE = 'SIGN_PROCESS_DOCUMENT_NOT_AVAILABLE';

	/**
	 * Groups read from the storage per pass while a page is being filled, and the ceiling of such passes.
	 * A page cannot be cut out by the storage alone: the right to see a row is asked of the engine per row,
	 * so the groups are read in passes until the page is full. The ceiling keeps a request bounded on a
	 * portal where almost nothing is visible to the person looking at the list.
	 */
	private const SCAN_CHUNK_SIZE = 50;
	private const ITEM_SCAN_CHUNK_SIZE = 100;
	private const MAX_SCANNED_GROUPS = 2000;

	private const PROTOCOL_URL = '/bitrix/components/bitrix/bizproc.log/slider.php?WORKFLOW_ID=';

	private readonly LaunchRepository $launchRepository;
	private readonly ProcessTypeService $processTypeService;
	private readonly DocumentRepository $documentRepository;

	/** @var array<string, bool> */
	private array $processAccessCache = [];

	/** @var array<string, bool> */
	private array $documentAccessCache = [];

	/** @var array<int, AccessController> */
	private array $accessControllers = [];

	/** @var array<int, bool> */
	private array $hasAnyRunningByUser = [];

	public function __construct(
		?LaunchRepository $launchRepository = null,
		?ProcessTypeService $processTypeService = null,
		?DocumentRepository $documentRepository = null,
	)
	{
		$container = Container::instance();
		$this->launchRepository = $launchRepository ?? $container->getProcessLaunchRepository();
		$this->processTypeService = $processTypeService ?? $container->getCrmProcessTypeService();
		$this->documentRepository = $documentRepository ?? $container->getDocumentRepository();
	}

	/**
	 * Top level of the list: the launch groups the person may see, the newest first. A group none of whose
	 * processes are visible to them does not appear at all - neither as a row nor in the counting.
	 *
	 * @return ProcessPage of {@see LaunchGroup}
	 */
	public function listGroups(
		int $userId,
		?RunningProcessFilterState $state = null,
		?string $searchQuery = null,
		int $limit = self::PAGE_SIZE,
		int $offset = 0,
	): ProcessPage
	{
		$entityTypeId = $this->getEntityTypeId();
		if ($userId < 1 || $limit < 1 || $offset < 0 || $entityTypeId === null)
		{
			return ProcessPage::emptyPage();
		}

		$state ??= RunningProcessFilterState::makeDefault();
		$collected = [];
		$matched = 0;
		$scanned = 0;
		$isSourceExhausted = false;

		while (count($collected) <= $limit && $scanned < self::MAX_SCANNED_GROUPS)
		{
			$remaining = $offset + $limit + 1 - $matched;
			$scanLimit = min(self::SCAN_CHUNK_SIZE, max(1, $remaining));
			$launches = $this->launchRepository->listByEntityTypeId($entityTypeId, $scanLimit, $scanned);
			$scanned += count($launches);
			$isSourceExhausted = count($launches) < $scanLimit;
			if ($launches === [])
			{
				break;
			}

			foreach ($this->buildGroups($entityTypeId, $launches, $userId, $state, $searchQuery) as $group)
			{
				$matched++;
				if ($matched > $offset)
				{
					$collected[] = $group;
				}

				if (count($collected) > $limit)
				{
					break;
				}
			}

			if ($isSourceExhausted)
			{
				break;
			}
		}

		return new ProcessPage(array_slice($collected, 0, $limit), !$isSourceExhausted || count($collected) > $limit);
	}

	/**
	 * The composition of one group. Neither the state filter nor the search is applied here: the number of
	 * employees of the group row and its opened composition are counted on the same basis, and a
	 * filtered composition would disagree with the number the person has just seen.
	 *
	 * @return ProcessPage of {@see RunningProcess}
	 */
	public function listGroupProcesses(
		int $userId,
		int $launchId,
		int $limit = self::PAGE_SIZE,
		int $offset = 0,
	): ProcessPage
	{
		$entityTypeId = $this->getEntityTypeId();
		if ($userId < 1 || $launchId < 1 || $limit < 1 || $offset < 0 || $entityTypeId === null)
		{
			return ProcessPage::emptyPage();
		}

		$launch = $this->launchRepository->getById($launchId);
		if ($launch === null || $launch->entityTypeId !== $entityTypeId)
		{
			return ProcessPage::emptyPage();
		}

		$page = [];
		$visibleCount = 0;
		$afterId = 0;
		do
		{
			$candidates = $this->launchRepository->listItemsPageByLaunchIds(
				[$launchId],
				self::ITEM_SCAN_CHUNK_SIZE,
				$afterId,
			);
			if ($candidates === [])
			{
				break;
			}

			$afterId = $candidates[array_key_last($candidates)]->getId();
			foreach ($candidates as $item)
			{
				if (!$this->isItemVisible($launch, $item, $userId))
				{
					continue;
				}

				$visibleCount++;
				if ($visibleCount <= $offset)
				{
					continue;
				}

				$page[] = $item;
				if (count($page) > $limit)
				{
					break 2;
				}
			}
		}
		while (count($candidates) === self::ITEM_SCAN_CHUNK_SIZE);

		$pageItems = array_slice($page, 0, $limit);
		$processes = $this->buildProcesses(
			$entityTypeId,
			[$launchId => $launch],
			[$launchId => $pageItems],
			$userId,
		);

		return new ProcessPage(
			$processes[$launchId] ?? [],
			count($page) > $limit,
		);
	}

	/**
	 * Whether the section has at least one running process visible to the person. The answer serves the mark
	 * on the pill of the tab, so it is built by the very mechanism the list is built by - a differently
	 * counted mark would light up over an empty list.
	 */
	public function hasAnyRunning(int $userId): bool
	{
		if (isset($this->hasAnyRunningByUser[$userId]))
		{
			return $this->hasAnyRunningByUser[$userId];
		}

		$entityTypeId = $this->getEntityTypeId();
		if ($userId < 1 || $entityTypeId === null)
		{
			return $this->hasAnyRunningByUser[$userId] = false;
		}

		$scanned = 0;
		while ($scanned < self::MAX_SCANNED_GROUPS)
		{
			$launches = $this->launchRepository->listByEntityTypeId(
				$entityTypeId,
				self::SCAN_CHUNK_SIZE,
				$scanned,
			);
			$scanned += count($launches);
			if ($launches === [])
			{
				break;
			}

			if ($this->hasVisiblePendingItem($launches, $userId) || $this->hasVisibleRunningItem($launches, $userId))
			{
				return $this->hasAnyRunningByUser[$userId] = true;
			}

			if (count($launches) < self::SCAN_CHUNK_SIZE)
			{
				break;
			}
		}

		return $this->hasAnyRunningByUser[$userId] = false;
	}

	/**
	 * The process a signing document was created by - the line "Created by the process ..." of its card.
	 *
	 * Two rights are counted here and they are not the same right: the document has to be the business of
	 * the person asking at all, and the process that created it is theirs to see or not on its own. A
	 * document they may not see is refused; a process they may not see leaves the answer empty, exactly as
	 * a document no process created does - the card shows the line or does not, and it has no business
	 * telling those two apart.
	 *
	 * @return Result of data{process: ProcessOrigin|null}, with {@see self::ERROR_DOCUMENT_NOT_AVAILABLE}
	 *     when the document is not the business of the person asking
	 */
	public function getProcessByDocument(int $userId, int $documentId): Result
	{
		$result = new Result();
		$document = $userId > 0 && $documentId > 0 ? $this->documentRepository->getById($documentId) : null;
		if (
			$document === null
			|| $document->entityType !== EntityType::SMART_B2E
			|| !$this->canViewDocument($userId, $document)
		)
		{
			return $result->addError(new Error('Document is not available', self::ERROR_DOCUMENT_NOT_AVAILABLE));
		}

		return $result->setData(['process' => $this->findProcessOfDocument($userId, $document)]);
	}

	/**
	 * @param list<Launch> $launches
	 *
	 * @return list<LaunchGroup>
	 */
	private function buildGroups(
		int $entityTypeId,
		array $launches,
		int $userId,
		RunningProcessFilterState $state,
		?string $searchQuery,
	): array
	{
		$launchesById = [];
		foreach ($launches as $launch)
		{
			$launchesById[$launch->getId()] = $launch;
		}

		$summaries = $this->summarizeVisibleItems($launchesById, $userId);
		if ($summaries === [])
		{
			return [];
		}

		$processNames = $this->loadProcessNames($launchesById);
		$matched = [];
		$singleItemsByLaunch = [];
		foreach ($launchesById as $launchId => $launch)
		{
			$summary = $summaries[$launchId] ?? null;
			if ($summary === null)
			{
				continue;
			}

			$processName = $processNames[$launch->templateId] ?? '';
			if (!$state->covers($summary['status']) || !$this->matchesSearchQuery($processName, $searchQuery))
			{
				continue;
			}

			$matched[$launchId] = $summary + ['processName' => $processName];
			if ($summary['memberCount'] === 1)
			{
				$singleItemsByLaunch[$launchId] = [$summary['firstItem']];
			}
		}

		if ($matched === [])
		{
			return [];
		}

		// A group of one employee is shown as an ordinary row and does not open. Only those first
		// rows that survived visibility and list filters are hydrated, together for the whole candidate page.
		$processesByLaunch = $this->buildProcesses($entityTypeId, $launchesById, $singleItemsByLaunch, $userId);

		$groups = [];
		foreach ($matched as $launchId => $summary)
		{
			$launch = $launchesById[$launchId];

			$groups[] = new LaunchGroup(
				launchId: $launchId,
				processName: $summary['processName'],
				memberCount: $summary['memberCount'],
				status: $summary['status'],
				startedTs: $launch->queuedAt->getTimestamp(),
				process: $processesByLaunch[$launchId][0] ?? null,
			);
		}

		return $groups;
	}

	/**
	 * Reads compositions in bounded pages and keeps only a summary per launch. Access checks still cover the
	 * whole composition because memberCount is the exact number of visible processes; retaining every item in
	 * memory is not required for that contract.
	 *
	 * @param array<int, Launch> $launchesById
	 *
	 * @return array<int, array{memberCount: int, status: RunningProcessStatus, firstItem: LaunchItem}>
	 */
	private function summarizeVisibleItems(array $launchesById, int $userId): array
	{
		$summaries = [];
		$afterId = 0;
		do
		{
			$items = $this->launchRepository->listItemsPageByLaunchIds(
				array_keys($launchesById),
				self::ITEM_SCAN_CHUNK_SIZE,
				$afterId,
			);
			if ($items === [])
			{
				break;
			}

			$afterId = $items[array_key_last($items)]->getId();
			$startedItems = [];
			foreach ($items as $item)
			{
				$launch = $launchesById[$item->launchId] ?? null;
				if ($launch === null || !$this->isItemVisible($launch, $item, $userId))
				{
					continue;
				}

				if (!isset($summaries[$item->launchId]))
				{
					$summaries[$item->launchId] = [
						'memberCount' => 0,
						'status' => RunningProcessStatus::Completed,
						'firstItem' => $item,
					];
				}

				$summaries[$item->launchId]['memberCount']++;
				if ($item->status === LaunchItemStatus::Started)
				{
					$startedItems[] = $item;

					continue;
				}

				$summaries[$item->launchId]['status'] = $this->resolveGroupStatus([
					$summaries[$item->launchId]['status'],
					$this->resolveProcessStatus($item, []),
				]);
			}

			$states = $this->loadWorkflowStates(array_values(array_filter(array_map(
				static fn(LaunchItem $item): ?string => $item->workflowId,
				$startedItems,
			))));
			foreach ($startedItems as $item)
			{
				$summaries[$item->launchId]['status'] = $this->resolveGroupStatus([
					$summaries[$item->launchId]['status'],
					$this->resolveProcessStatus($item, $states),
				]);
			}
		}
		while (count($items) === self::ITEM_SCAN_CHUNK_SIZE);

		return $summaries;
	}

	/**
	 * Rows of a whole page, whether the page is the composition of one group or the single rows of many
	 * groups. Everything a row needs beyond the launch itself is read for the whole page at once: a reading
	 * per row would grow with the size of the page and with the size of a group.
	 *
	 * @param array<int, Launch> $launchesById
	 * @param array<int, list<LaunchItem>> $itemsByLaunch
	 *
	 * @return array<int, list<RunningProcess>>
	 */
	private function buildProcesses(int $entityTypeId, array $launchesById, array $itemsByLaunch, int $userId): array
	{
		if ($itemsByLaunch === [])
		{
			return [];
		}

		$workflowIds = [];
		$entityIds = [];
		foreach ($itemsByLaunch as $items)
		{
			foreach ($items as $item)
			{
				if ($item->workflowId !== null)
				{
					$workflowIds[] = $item->workflowId;
				}
				if ($item->entityId !== null)
				{
					$entityIds[] = $item->entityId;
				}
			}
		}

		$states = $this->loadWorkflowStates($workflowIds);
		$participants = $this->loadParticipants($workflowIds);
		$titles = $this->loadItemTitles($entityTypeId, $entityIds);
		$documents = $this->loadDocuments($entityTypeId, $entityIds, $userId);

		$processes = [];
		foreach ($itemsByLaunch as $launchId => $items)
		{
			$launch = $launchesById[$launchId] ?? null;
			if ($launch === null)
			{
				continue;
			}

			foreach ($items as $item)
			{
				$status = $this->resolveProcessStatus($item, $states);
				$state = $item->workflowId === null ? null : ($states[$item->workflowId] ?? null);
				$itemDocuments = $item->entityId === null ? [] : ($documents[$item->entityId] ?? []);

				$processes[$launchId][] = new RunningProcess(
					launchId: $launchId,
					launchItemId: $item->getId(),
					employeeId: $item->employeeId,
					status: $status,
					documentsState: $this->resolveDocumentsState($status, $itemDocuments),
					workflowId: $item->workflowId,
					entityId: $item->entityId,
					title: $item->entityId === null ? '' : ($titles[$item->entityId] ?? ''),
					stageName: (string)($state['stageName'] ?? ''),
					lastActivityTs: $state['lastActivityTs'] ?? null,
					participants: $item->workflowId === null ? [] : ($participants[$item->workflowId] ?? []),
					documents: $itemDocuments,
					noDocumentReason: $this->resolveNoDocumentReason($status, $state, $itemDocuments),
					canRetry: $item->status === LaunchItemStatus::Error && $launch->createdById === $userId,
					protocolUrl: $item->workflowId === null ? null : self::PROTOCOL_URL . $item->workflowId,
				);
			}
		}

		return $processes;
	}

	private function isItemVisible(Launch $launch, LaunchItem $item, int $userId): bool
	{
		return $item->entityId === null
			? $launch->createdById === $userId
			: $this->canViewProcessItem($userId, $launch->entityTypeId, $item->entityId);
	}

	/**
	 * Queued and failed rows are non-completed by their persisted state, so the pill probe can push that
	 * restriction into the indexed query and stop at the first visible row.
	 *
	 * @param list<Launch> $launches
	 */
	private function hasVisiblePendingItem(array $launches, int $userId): bool
	{
		$launchesById = [];
		foreach ($launches as $launch)
		{
			$launchesById[$launch->getId()] = $launch;
		}

		$afterId = 0;
		do
		{
			$items = $this->launchRepository->listItemsPageByLaunchIds(
				array_keys($launchesById),
				self::ITEM_SCAN_CHUNK_SIZE,
				$afterId,
				[LaunchItemStatus::Queued, LaunchItemStatus::Error],
			);
			if ($items === [])
			{
				return false;
			}

			$afterId = $items[array_key_last($items)]->getId();
			foreach ($items as $item)
			{
				$launch = $launchesById[$item->launchId] ?? null;
				if ($launch !== null && $this->isItemVisible($launch, $item, $userId))
				{
					return true;
				}
			}
		}
		while (count($items) === self::ITEM_SCAN_CHUNK_SIZE);

		return false;
	}

	/**
	 * A started row belongs to the in-work pill only while its workflow instance still exists. Candidates
	 * and engine states are both read in bounded pages; no titles, participants or signing documents are
	 * hydrated for this boolean answer.
	 *
	 * @param list<Launch> $launches
	 */
	private function hasVisibleRunningItem(array $launches, int $userId): bool
	{
		$launchesById = [];
		foreach ($launches as $launch)
		{
			$launchesById[$launch->getId()] = $launch;
		}

		$afterId = 0;
		do
		{
			$items = $this->launchRepository->listItemsPageByLaunchIds(
				array_keys($launchesById),
				self::ITEM_SCAN_CHUNK_SIZE,
				$afterId,
				[LaunchItemStatus::Started],
			);
			if ($items === [])
			{
				return false;
			}

			$afterId = $items[array_key_last($items)]->getId();
			$visibleItems = [];
			foreach ($items as $item)
			{
				$launch = $launchesById[$item->launchId] ?? null;
				if ($launch !== null && $this->isItemVisible($launch, $item, $userId))
				{
					$visibleItems[] = $item;
				}
			}

			$states = $this->loadWorkflowStates(array_values(array_filter(array_map(
				static fn(LaunchItem $item): ?string => $item->workflowId,
				$visibleItems,
			))));
			foreach ($visibleItems as $item)
			{
				if ($this->resolveProcessStatus($item, $states) === RunningProcessStatus::InWork)
				{
					return true;
				}
			}
		}
		while (count($items) === self::ITEM_SCAN_CHUNK_SIZE);

		return false;
	}

	protected function canViewProcessItem(int $userId, int $entityTypeId, int $entityId): bool
	{
		$cacheKey = "$userId|$entityTypeId|$entityId";
		if (isset($this->processAccessCache[$cacheKey]))
		{
			return $this->processAccessCache[$cacheKey];
		}

		$documentId = $this->resolveDocumentId($entityTypeId, $entityId);
		$this->processAccessCache[$cacheKey] = $documentId !== null && \CBPDocument::canUserOperateDocument(
			\CBPCanUserOperateOperation::ViewWorkflow,
			$userId,
			$documentId,
		);

		return $this->processAccessCache[$cacheKey];
	}

	/**
	 * The right to see a signing document is counted apart from the right to see the process: the person may
	 * be allowed to watch the process and still have no business seeing what it has signed.
	 */
	protected function canViewDocument(int $userId, Item\Document $document): bool
	{
		$cacheKey = "$userId|{$document->id}";

		return $this->documentAccessCache[$cacheKey] ??= (new CheckDocumentAccess(
			$document,
			PermissionDictionary::SIGN_CRM_SMART_B2E_DOC_READ,
			$this->accessControllers[$userId] ??= new AccessController($userId),
		))
			->launch()
			->isSuccess()
		;
	}

	/**
	 * The state of a row. The state of a started process is not stored anywhere on our side: a process that
	 * still has an instance is running, and an instance that is gone means the process is over - the engine
	 * removes it on both the ordinary completion and the termination
	 * ({@see \CBPWorkflowPersister::insertWorkflow()}).
	 *
	 * @param array<string, array{stageName: string, lastActivityTs: int|null, isRunning: bool}> $states
	 */
	private function resolveProcessStatus(LaunchItem $item, array $states): RunningProcessStatus
	{
		if ($item->status === LaunchItemStatus::Error)
		{
			return RunningProcessStatus::Error;
		}

		if ($item->status === LaunchItemStatus::Queued)
		{
			return RunningProcessStatus::Launching;
		}

		$state = $item->workflowId === null ? null : ($states[$item->workflowId] ?? null);

		return ($state['isRunning'] ?? false) ? RunningProcessStatus::InWork : RunningProcessStatus::Completed;
	}

	/**
	 * The state of a group derived from the states of its rows. A row that asks something of the person comes
	 * first: a failed launch waits for a retry, and a group still being served is not finished even
	 * if part of it already is.
	 *
	 * @param list<RunningProcessStatus> $statuses
	 */
	private function resolveGroupStatus(array $statuses): RunningProcessStatus
	{
		foreach ([RunningProcessStatus::Error, RunningProcessStatus::Launching, RunningProcessStatus::InWork] as $status)
		{
			if (in_array($status, $statuses, true))
			{
				return $status;
			}
		}

		return RunningProcessStatus::Completed;
	}

	/**
	 * @param list<ProcessDocument> $documents
	 */
	private function resolveDocumentsState(RunningProcessStatus $status, array $documents): DocumentsState
	{
		if ($documents !== [])
		{
			return DocumentsState::Created;
		}

		return match ($status)
		{
			RunningProcessStatus::Launching, RunningProcessStatus::InWork => DocumentsState::None,
			RunningProcessStatus::Completed, RunningProcessStatus::Error => DocumentsState::NotCreated,
		};
	}

	/**
	 * Why the process left no document. The exact reason is not available on a portal out of the box: the
	 * engine suppresses the tracking of a skipped activity by default ({@see \CBPTrackingService::canWrite()}
	 * together with the default value of the option bizproc.log_skip_types), and the retention agent removes
	 * what is left of it. What remains is the state of the process itself, so the wording is a common one.
	 *
	 * A process whose state the engine no longer keeps says nothing at all, and then the hint is not shown
	 * instead of being guessed.
	 *
	 * @param array{stageName: string, lastActivityTs: int|null, isRunning: bool}|null $state
	 * @param list<ProcessDocument> $documents
	 */
	private function resolveNoDocumentReason(
		RunningProcessStatus $status,
		?array $state,
		array $documents,
	): ?string
	{
		if ($documents !== [] || $status !== RunningProcessStatus::Completed || $state === null)
		{
			return null;
		}

		return (string)Loc::getMessage('SIGN_SERVICE_PROCESS_RUNNING_NO_DOCUMENT_REASON_COMPLETED');
	}

	/**
	 * @param list<string> $workflowIds
	 *
	 * @return array<string, array{stageName: string, lastActivityTs: int|null, isRunning: bool}>
	 */
	private function loadWorkflowStates(array $workflowIds): array
	{
		$workflowIds = $this->uniqueStrings($workflowIds);
		if ($workflowIds === [] || !Loader::includeModule('bizproc'))
		{
			return [];
		}

		$states = [];
		$rows = WorkflowStateTable::query()
			->setSelect(['ID', 'STATE_TITLE', 'MODIFIED', 'INSTANCE_ID' => 'INSTANCE.ID'])
			->whereIn('ID', $workflowIds)
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$modified = $row['MODIFIED'] ?? null;
			$states[(string)$row['ID']] = [
				'stageName' => (string)($row['STATE_TITLE'] ?? ''),
				'lastActivityTs' => $modified instanceof DateTime ? $modified->getTimestamp() : null,
				'isRunning' => ($row['INSTANCE_ID'] ?? null) !== null,
			];
		}

		return $states;
	}

	/**
	 * Who the processes are waiting for right now, and since when. Only a task that is still running and a
	 * person who has not answered it yet hold a process, so a completed task leaves nobody on the row.
	 *
	 * @param list<string> $workflowIds
	 *
	 * @return array<string, list<ProcessParticipant>>
	 */
	private function loadParticipants(array $workflowIds): array
	{
		$workflowIds = $this->uniqueStrings($workflowIds);
		if ($workflowIds === [] || !Loader::includeModule('bizproc'))
		{
			return [];
		}

		$rows = TaskUserTable::query()
			->setSelect([
				'USER_ID',
				'WORKFLOW_ID' => 'USER_TASKS.WORKFLOW_ID',
				'CREATED_DATE' => 'USER_TASKS.CREATED_DATE',
			])
			->whereIn('USER_TASKS.WORKFLOW_ID', $workflowIds)
			->where('STATUS', \CBPTaskUserStatus::Waiting)
			->where('USER_TASKS.STATUS', \CBPTaskStatus::Running)
			->addOrder('USER_TASKS.CREATED_DATE')
			->fetchAll()
		;

		$participants = [];
		foreach ($rows as $row)
		{
			$workflowId = (string)$row['WORKFLOW_ID'];
			$userId = (int)$row['USER_ID'];
			if ($userId < 1 || isset($participants[$workflowId][$userId]))
			{
				continue;
			}

			$createdDate = $row['CREATED_DATE'] ?? null;
			$participants[$workflowId][$userId] = new ProcessParticipant(
				userId: $userId,
				holdingSinceTs: $createdDate instanceof DateTime ? $createdDate->getTimestamp() : null,
			);
		}

		return array_map('array_values', $participants);
	}

	/**
	 * @param int[] $entityIds
	 *
	 * @return array<int, string>
	 */
	protected function loadItemTitles(int $entityTypeId, array $entityIds): array
	{
		$entityIds = $this->uniqueIds($entityIds);
		if ($entityIds === [] || !Loader::includeModule('crm'))
		{
			return [];
		}

		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return [];
		}

		$titles = [];
		foreach ($factory->getItems(['select' => ['ID', 'TITLE'], 'filter' => ['@ID' => $entityIds]]) as $item)
		{
			$titles[$item->getId()] = (string)$item->getTitle();
		}

		return $titles;
	}

	/**
	 * The documents of the processes of a page, read in one pass. There is no batch reading of bound elements
	 * in crm - {@see \Bitrix\Crm\Relation\RelationManager::getChildElements()} answers about one element - so
	 * the same storage of the relation is read directly: the binding of any dynamic type to SmartB2eDocument
	 * is predefined and always kept by {@see \Bitrix\Crm\Relation\StorageStrategy\EntityRelationTable}.
	 *
	 * The cell shows every document of the process and not the first one.
	 *
	 * @param int[] $entityIds
	 *
	 * @return array<int, list<ProcessDocument>>
	 */
	private function loadDocuments(int $entityTypeId, array $entityIds, int $userId): array
	{
		$entityIds = $this->uniqueIds($entityIds);
		if ($entityIds === [] || !Loader::includeModule('crm'))
		{
			return [];
		}

		$relations = EntityRelationTable::query()
			->setSelect(['SRC_ENTITY_ID', 'DST_ENTITY_ID'])
			->where('SRC_ENTITY_TYPE_ID', $entityTypeId)
			->whereIn('SRC_ENTITY_ID', $entityIds)
			->where('DST_ENTITY_TYPE_ID', \CCrmOwnerType::SmartB2eDocument)
			->addOrder('DST_ENTITY_ID')
			->fetchAll()
		;
		if ($relations === [])
		{
			return [];
		}

		$documentsByEntity = $this->loadSignDocuments(array_column($relations, 'DST_ENTITY_ID'), $userId);

		$documents = [];
		foreach ($relations as $relation)
		{
			$document = $documentsByEntity[(int)$relation['DST_ENTITY_ID']] ?? null;
			if ($document !== null)
			{
				$documents[(int)$relation['SRC_ENTITY_ID']][] = $document;
			}
		}

		return $documents;
	}

	/**
	 * The bound elements turned into the documents of the module. A document the person may not see stays in
	 * the list without a name and without a way to open it: the fact that the process reached signing belongs
	 * to the process, the contents of the document do not.
	 *
	 * @param int[] $documentEntityIds
	 *
	 * @return array<int, ProcessDocument>
	 */
	private function loadSignDocuments(array $documentEntityIds, int $userId): array
	{
		$documentEntityIds = $this->uniqueIds($documentEntityIds);
		if ($documentEntityIds === [])
		{
			return [];
		}

		$documents = [];
		$collection = $this->documentRepository->listByEntityIdsAndType($documentEntityIds, EntityType::SMART_B2E);
		foreach ($collection as $document)
		{
			if ($document->id === null || $document->entityId === null)
			{
				continue;
			}

			$isVisible = $this->canViewDocument($userId, $document);
			$documents[$document->entityId] = new ProcessDocument(
				id: $document->id,
				title: $isVisible ? (string)$document->title : '',
				statusName: $isVisible ? $this->getDocumentStatusName($document) : '',
				url: $isVisible ? $this->getDocumentUrl($document) : null,
			);
		}

		return $documents;
	}

	/**
	 * The process of one document, the way the cell of the documents is read backwards. A process the
	 * person may not see and a process that never was are one and the same empty answer.
	 *
	 * A process of the same type started outside of KEDO is left without an answer as well: it has no row
	 * of a launch group, the row is the only place that names the process of an element, and the module
	 * does not show what it cannot name - the same way such a process gets no line in the list of the
	 * started ones and no deal in the timeline of its documents.
	 */
	private function findProcessOfDocument(int $userId, Item\Document $document): ?ProcessOrigin
	{
		$entityTypeId = $this->getEntityTypeId();
		$documentEntityId = (int)$document->entityId;
		if ($entityTypeId === null || $documentEntityId < 1)
		{
			return null;
		}

		$processEntityId = $this->resolveProcessEntityId($entityTypeId, $documentEntityId);
		if ($processEntityId === null)
		{
			return null;
		}

		$item = $this->launchRepository->getItemByEntityId($processEntityId);
		if ($item === null || $item->workflowId === null || $item->workflowId === '')
		{
			return null;
		}

		$launch = $this->launchRepository->getById($item->launchId);
		if ($launch === null || $launch->entityTypeId !== $entityTypeId)
		{
			return null;
		}

		if (!$this->canViewProcessItem($userId, $entityTypeId, $processEntityId))
		{
			return null;
		}

		$url = Entity::getDetailPageUri($entityTypeId, $processEntityId)?->getUri();

		return $url === null ? null : new ProcessOrigin(
			workflowId: $item->workflowId,
			entityId: $processEntityId,
			name: $this->loadProcessNames([$launch->getId() => $launch])[$launch->templateId] ?? '',
			url: $url,
		);
	}

	/**
	 * The element of a personnel process a document is tied to. The tie is the one the action of signing
	 * writes ({@see \Bitrix\Sign\Service\Integration\Crm\EntityRelationService::addRelationToSmartB2eDocument()}),
	 * so the way back from a document to its process reads the very storage the cell of the documents
	 * reads and needs no tie of its own; the reading by the bound element rests on the index over
	 * DST_ENTITY_ID.
	 *
	 * The process creates the document and ties it to its own element, so one such element is the whole
	 * answer: a second tie made by hand is answered by the first of them.
	 */
	private function resolveProcessEntityId(int $entityTypeId, int $documentEntityId): ?int
	{
		if (!Loader::includeModule('crm'))
		{
			return null;
		}

		$row = EntityRelationTable::query()
			->setSelect(['SRC_ENTITY_ID'])
			->where('SRC_ENTITY_TYPE_ID', $entityTypeId)
			->where('DST_ENTITY_TYPE_ID', \CCrmOwnerType::SmartB2eDocument)
			->where('DST_ENTITY_ID', $documentEntityId)
			->addOrder('SRC_ENTITY_ID')
			->setLimit(1)
			->fetch()
		;

		return $row === false ? null : (int)$row['SRC_ENTITY_ID'];
	}

	private function getDocumentStatusName(Item\Document $document): string
	{
		return $document->status === null ? '' : RestDocumentStatus::getDocumentStatusName($document->status);
	}

	private function getDocumentUrl(Item\Document $document): ?string
	{
		if ($document->entityTypeId === null || $document->entityId === null)
		{
			return null;
		}

		return Entity::getDetailPageUri($document->entityTypeId, $document->entityId)?->getUri();
	}

	/**
	 * Names of the processes of a page. The name is taken from the template and not from the state of the
	 * engine: a group whose rows are all still starting has no states at all, and the row of such a group
	 * must still be named.
	 *
	 * @param array<int, Launch> $launchesById
	 *
	 * @return array<int, string>
	 */
	private function loadProcessNames(array $launchesById): array
	{
		$templateIds = $this->uniqueIds(
			array_map(static fn(Launch $launch): int => $launch->templateId, $launchesById),
		);
		if ($templateIds === [] || !Loader::includeModule('bizproc'))
		{
			return [];
		}

		$names = [];
		$rows = WorkflowTemplateTable::query()
			->setSelect(['ID', 'NAME'])
			->whereIn('ID', $templateIds)
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$names[(int)$row['ID']] = (string)($row['NAME'] ?? '');
		}

		return $names;
	}

	protected function getEntityTypeId(): ?int
	{
		return $this->processTypeService->getEntityTypeId();
	}

	private function resolveDocumentId(int $entityTypeId, int $entityId): ?array
	{
		if (!Loader::includeModule('bizproc') || !Loader::includeModule('crm'))
		{
			return null;
		}

		return \CCrmBizProcHelper::ResolveDocumentId($entityTypeId, $entityId);
	}

	private function matchesSearchQuery(string $name, ?string $searchQuery): bool
	{
		$searchQuery = trim($searchQuery ?? '');

		return $searchQuery === '' || mb_stripos($name, $searchQuery) !== false;
	}

	/**
	 * @param int[] $ids
	 *
	 * @return list<int>
	 */
	private function uniqueIds(array $ids): array
	{
		return array_values(
			array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)),
		);
	}

	/**
	 * @param string[] $values
	 *
	 * @return list<string>
	 */
	private function uniqueStrings(array $values): array
	{
		return array_values(array_unique(array_filter($values, static fn(string $value): bool => $value !== '')));
	}
}
