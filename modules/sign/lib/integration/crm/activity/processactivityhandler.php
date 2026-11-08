<?php

namespace Bitrix\Sign\Integration\CRM\Activity;

use Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Sign\Integration\CRM\Activity\Provider\Process;
use Bitrix\Sign\Item\Process\LaunchItem;
use Bitrix\Sign\Repository\Process\LaunchRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\Crm\ProcessTypeService;

/**
 * Lifecycle of the deal "Process: ..." in the timeline of a document a personnel process created. The
 * deal is written where the document is tied to the element of the process and closed where the engine
 * says the process is over.
 *
 * Both directions meet in the row of the launch group. The deal points at the element of the process,
 * while the engine names only the identifier of the process, and the row is the only place that knows
 * both. A process of the same type started outside of KEDO has no such row and gets no deal, the same
 * way it gets no line in the list of the started processes.
 *
 * The order of the two moments is not guaranteed. Closing is idempotent - a deal that is already closed
 * is left alone - and a document tied to a process that has already ended gets a deal that is closed
 * from the start, instead of one that stays open forever because the event it waited for is long gone.
 */
class ProcessActivityHandler
{
	private readonly LaunchRepository $launchRepository;
	private readonly ProcessTypeService $processTypeService;

	public function __construct(
		?LaunchRepository $launchRepository = null,
		?ProcessTypeService $processTypeService = null,
	)
	{
		$container = Container::instance();
		$this->launchRepository = $launchRepository ?? $container->getProcessLaunchRepository();
		$this->processTypeService = $processTypeService ?? $container->getCrmProcessTypeService();
	}

	/**
	 * Compatible bizproc event OnWorkflowComplete, fired for a completed and for a terminated process
	 * alike. Its second positional argument, the status, is not read: both statuses mean the process is
	 * over, and the deal knows no third state.
	 */
	public static function onWorkflowComplete(mixed $workflowId): void
	{
		Container::instance()->getCrmProcessActivityHandler()->completeByWorkflowId((string)$workflowId);
	}

	/**
	 * Modern bizproc event onAfterWorkflowKill. It arrives after the state of the process has already
	 * been deleted, which is why the way from the identifier of the process to the element it ran on
	 * goes through the row of the launch and never through the engine.
	 */
	public static function onAfterWorkflowKill(Event $event): void
	{
		Container::instance()
			->getCrmProcessActivityHandler()
			->completeByWorkflowId((string)$event->getParameter('ID'))
		;
	}

	/**
	 * Writes the deal of a document that has just been tied to the element of a personnel process.
	 *
	 * Everything that is not such a tie leaves without a deal and without an error: the method is called
	 * from the binding of every document of the module, and a binding to a deal, to a lead or to a smart
	 * process of somebody else is the ordinary case, not a failure.
	 */
	public function createForBoundDocument(int $documentEntityId, int $entityTypeId, int $processElementId): Result
	{
		$result = new Result();
		if ($documentEntityId < 1 || $processElementId < 1)
		{
			return $result;
		}

		$processTypeId = $this->getProcessEntityTypeId();
		if ($processTypeId === null || $entityTypeId !== $processTypeId)
		{
			return $result;
		}

		$item = $this->launchRepository->getItemByEntityId($processElementId);
		$launch = $item === null ? null : $this->launchRepository->getById($item->launchId);
		if ($item === null || $launch === null)
		{
			return $result;
		}

		// a repeated binding of the same document must not add a second deal about the same process
		if ($this->findActivities($processElementId, $documentEntityId) !== [])
		{
			return $result;
		}

		return $this->addActivity(
			$documentEntityId,
			$processElementId,
			$this->loadProcessName($launch->templateId),
			$launch->createdById,
			!$this->isProcessRunning($item),
		);
	}

	/**
	 * Closes the deals of the documents of a process that has ended - the yellow row of the timeline
	 * turns white.
	 *
	 * Deliberately not gated by the feature flag: closing the flag hides the deal, it does not change
	 * what is written, and a completion skipped while the flag was down would leave the deal open for
	 * good once the flag is raised again.
	 */
	public function completeByWorkflowId(string $workflowId): Result
	{
		$result = new Result();
		$item = $this->launchRepository->getItemByWorkflowId($workflowId);
		if ($item === null || (int)$item->entityId < 1)
		{
			return $result;
		}

		// one process may have created several documents, and every one of them carries a deal of its own
		foreach ($this->findActivities((int)$item->entityId) as $activity)
		{
			if ($activity['COMPLETED'] === 'Y')
			{
				continue;
			}

			if (!$this->completeActivity($activity['ID']))
			{
				$result->addError(new Error('Deal ' . $activity['ID'] . ' of the process was not completed'));
			}
		}

		return $result;
	}

	protected function addActivity(
		int $documentEntityId,
		int $processElementId,
		string $processName,
		int $responsibleId,
		bool $isCompleted,
	): Result
	{
		if (!Loader::includeModule('crm'))
		{
			return (new Result())->addError(new Error('Module crm is not available'));
		}

		return $this->writeActivity([
			// the document goes first: the primary binding is what the deal counts the right to read
			// itself by, and a row led by the element of the process would be refused as a whole
			'BINDINGS' => [
				[
					'OWNER_TYPE_ID' => \CCrmOwnerType::SmartB2eDocument,
					'OWNER_ID' => $documentEntityId,
				],
			],
			'ASSOCIATED_ENTITY_ID' => $processElementId,
			'SUBJECT' => Process::generateSubject(
				Process::PROVIDER_TYPE_ID_PROCESS,
				\CCrmActivityDirection::Undefined,
				['#PROCESS_NAME#' => $processName],
			),
			'COMPLETED' => $isCompleted ? 'Y' : 'N',
			'RESPONSIBLE_ID' => $responsibleId,
		]);
	}

	protected function writeActivity(array $fields): Result
	{
		return (new Process())->createActivity(Process::PROVIDER_TYPE_ID_PROCESS, $fields);
	}

	/**
	 * Smart process the personnel processes of the portal run on, or null while the feature is closed or
	 * the type has not been created yet. A portal with the feature closed writes no deal at all - the deals
	 * it already has stay where they are, because a flag decides what is shown and not what is stored.
	 */
	protected function getProcessEntityTypeId(): ?int
	{
		return $this->processTypeService->getEntityTypeId();
	}

	/**
	 * Deals of one element of a process, optionally narrowed down to the one of a single document.
	 *
	 * @return list<array{ID: int, COMPLETED: string}>
	 */
	protected function findActivities(int $processElementId, ?int $documentEntityId = null): array
	{
		if ($processElementId < 1 || !Loader::includeModule('crm'))
		{
			return [];
		}

		$filter = [
			'TYPE_ID' => \CCrmActivityType::Provider,
			'PROVIDER_ID' => Process::PROVIDER_ID,
			'PROVIDER_TYPE_ID' => Process::PROVIDER_TYPE_ID_PROCESS,
			'ASSOCIATED_ENTITY_ID' => $processElementId,
			'CHECK_PERMISSIONS' => 'N',
		];
		if ($documentEntityId !== null)
		{
			$filter['OWNER_TYPE_ID'] = \CCrmOwnerType::SmartB2eDocument;
			$filter['OWNER_ID'] = $documentEntityId;
		}

		$activities = [];
		$cursor = \CCrmActivity::GetList([], $filter, false, false, ['ID', 'COMPLETED']);
		while ($row = $cursor->Fetch())
		{
			$activities[] = [
				'ID' => (int)$row['ID'],
				'COMPLETED' => ($row['COMPLETED'] ?? 'N') === 'Y' ? 'Y' : 'N',
			];
		}

		return $activities;
	}

	protected function completeActivity(int $activityId): bool
	{
		return Loader::includeModule('crm') && \CCrmActivity::Complete($activityId, true) !== false;
	}

	/**
	 * Whether the process of the row is still going. The instance of a process is dropped once the run is
	 * over, so its absence is what the list of the started processes reads as "finished" as well.
	 *
	 * A row whose process the queue has not started yet counts as running: nothing has ended there, and a
	 * deal closed from the start would be a lie about a process that is about to begin.
	 */
	protected function isProcessRunning(LaunchItem $item): bool
	{
		if ($item->workflowId === null || $item->workflowId === '' || !Loader::includeModule('bizproc'))
		{
			return true;
		}

		return WorkflowInstanceTable::query()
			->setSelect(['ID'])
			->where('ID', $item->workflowId)
			->fetch() !== false
		;
	}

	/**
	 * Name of the process as the list of the started processes shows it - the name of the template the
	 * group was queued for.
	 */
	protected function loadProcessName(int $templateId): string
	{
		if ($templateId < 1 || !Loader::includeModule('bizproc'))
		{
			return '';
		}

		$row = WorkflowTemplateTable::query()
			->setSelect(['NAME'])
			->where('ID', $templateId)
			->fetch()
		;

		return $row ? (string)($row['NAME'] ?? '') : '';
	}
}
