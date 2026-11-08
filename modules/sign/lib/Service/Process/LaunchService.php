<?php

namespace Bitrix\Sign\Service\Process;

use Bitrix\Bizproc\Starter\Enum\ManualStartSurface;
use Bitrix\Main;
use Bitrix\Main\Loader;
use Bitrix\Sign\Item\Process\Launch;
use Bitrix\Sign\Item\Process\LaunchItem;
use Bitrix\Sign\Repository\Process\LaunchRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\DateTime;
use Bitrix\Sign\Type\Process\LaunchItemStatus;
use Bitrix\Sign\Type\Process\LaunchStatus;

/**
 * Queue of the process launches: it turns a row waiting in a launch group into an item of the smart
 * process and a running process. A whole batch of rows is served per call, because the typical selection
 * of the feature is a department and one row per call would fit into no reasonable waiting term.
 *
 * The waiting term of a group belongs here as well: the term is bound to the throughput of this queue, so
 * the size of the batch, the interval of the agent and the term are changed together (Q-3 of the SDD).
 */
class LaunchService
{
	/**
	 * Rows served per call and the interval the agent of the queue is registered with. Both belong to the
	 * waiting term: getWaitingTermSeconds() is derived from them.
	 */
	public const QUEUE_BATCH_SIZE = 20;
	public const QUEUE_INTERVAL_SECONDS = 60;

	public const ERROR_LAUNCH_NOT_FOUND = 'SIGN_PROCESS_LAUNCH_NOT_FOUND';
	public const ERROR_NOT_AUTHOR = 'SIGN_PROCESS_LAUNCH_NOT_AUTHOR';
	public const ERROR_NOTHING_TO_RETRY = 'SIGN_PROCESS_LAUNCH_NOTHING_TO_RETRY';

	private const WAITING_TERM_RESERVE = 3;
	private const MIN_WAITING_TERM_SECONDS = 600;

	private const EXPIRED_GROUP_BATCH_SIZE = 20;

	private const ERROR_MESSAGE_LENGTH = 255;

	private const REASON_ACCESS_DENIED = 'access_denied';
	private const REASON_NO_LAUNCH = 'no_launch';
	private const REASON_ITEM_NOT_CREATED = 'item_not_created';
	private const REASON_NOT_STARTED = 'not_started';
	private const REASON_EXPIRED = 'expired';

	private readonly LaunchRepository $launchRepository;

	public function __construct(?LaunchRepository $launchRepository = null)
	{
		$this->launchRepository = $launchRepository ?? Container::instance()->getProcessLaunchRepository();
	}

	/**
	 * How long a group may wait for its rows to be served. The term grows with the size of the selection:
	 * a constant would send the very scenario the queue exists for - a whole department - into an error
	 * (Q-3). The floor covers a portal whose agents are called rarely, so that a launch of one employee is
	 * not declared failed before the queue has been touched at all.
	 */
	public function getWaitingTermSeconds(int $memberCount): int
	{
		$passes = (int)ceil(max($memberCount, 1) / self::QUEUE_BATCH_SIZE);
		$term = $passes * self::QUEUE_INTERVAL_SECONDS * self::WAITING_TERM_RESERVE;

		return max($term, self::MIN_WAITING_TERM_SECONDS);
	}

	/**
	 * Serves the rows waiting in the queue, oldest first.
	 *
	 * @return int rows served - started or moved to an error
	 */
	public function drainQueue(?int $limit = null): int
	{
		$items = $this->launchRepository->listQueuedItems($limit ?? self::QUEUE_BATCH_SIZE);
		if ($items === [])
		{
			return 0;
		}

		/** @var array<int, Launch|null> $launches */
		$launches = [];
		/** @var array<int, bool> $canStart */
		$canStart = [];
		$served = 0;

		foreach ($items as $item)
		{
			$launch = $launches[$item->launchId] ??= $this->launchRepository->getById($item->launchId);
			$served++;

			// a row whose group is gone can never be served, and leaving it waiting would keep it in
			// every batch from now on
			if ($launch === null)
			{
				$this->failItem($item, self::REASON_NO_LAUNCH);

				continue;
			}

			// the right is asked while the row is being served and not only when the group was queued:
			// the rights of the initiator could change while the row was waiting
			$canStart[$item->launchId] ??= $this->canStartProcess($launch);

			if ($canStart[$item->launchId])
			{
				$this->startItem($launch, $item);
			}
			else
			{
				$this->failItem($item, self::REASON_ACCESS_DENIED);
			}
		}

		foreach ($launches as $launch)
		{
			if ($launch !== null)
			{
				$this->refreshLaunchStatus($launch);
			}
		}

		return $served;
	}

	/**
	 * Rows that did not start within the term of their group. Such a row gets an error the initiator can
	 * act on - the interface offers a retry for it (AC-015c) - while a row left in "starting" forever
	 * offers nothing at all.
	 *
	 * @return int rows moved to an error
	 */
	public function expireOutdated(?DateTime $moment = null, ?int $limit = null): int
	{
		$moment ??= new DateTime();
		$expired = 0;

		foreach ($this->launchRepository->listExpired($moment, $limit ?? self::EXPIRED_GROUP_BATCH_SIZE) as $launch)
		{
			foreach ($this->launchRepository->listItems($launch->getId()) as $item)
			{
				if ($item->status === LaunchItemStatus::Queued)
				{
					$this->failItem($item, self::REASON_EXPIRED);
					$expired++;
				}
			}

			$this->refreshLaunchStatus($launch);
		}

		return $expired;
	}

	/**
	 * Sends the rows that did not start back into the queue and gives the group its waiting term anew. The
	 * group itself is not created again: the composition of the group and the number of employees in it stay
	 * what one press of the button made them, and a row that has already started is never touched - a second
	 * process would reach the employee (AC-015c).
	 *
	 * @param int|null $itemId one named row instead of every failed row of the group
	 */
	public function retry(int $launchId, int $initiatorId, ?int $itemId = null): Main\Result
	{
		$result = new Main\Result();

		$launch = $this->launchRepository->getById($launchId);
		if ($launch === null)
		{
			return $result->addError(new Main\Error('Launch group is not found', self::ERROR_LAUNCH_NOT_FOUND));
		}

		// the group belongs to the one who started it: a retry by anybody else would start processes on a
		// selection of employees they never chose
		if ($launch->createdById !== $initiatorId)
		{
			return $result->addError(
				new Main\Error('Launch group belongs to another user', self::ERROR_NOT_AUTHOR),
			);
		}

		$retried = 0;
		$waiting = 0;
		foreach ($this->launchRepository->listItems($launchId) as $item)
		{
			if ($item->status === LaunchItemStatus::Queued)
			{
				$waiting++;

				continue;
			}

			if ($item->status !== LaunchItemStatus::Error || ($itemId !== null && $item->getId() !== $itemId))
			{
				continue;
			}

			$this->requeueItem($item);
			$retried++;
		}

		if ($retried === 0)
		{
			return $result->addError(new Main\Error('No rows to retry', self::ERROR_NOTHING_TO_RETRY));
		}

		// the term is given for what waits from now on: the rows just returned and the ones that never left
		// the queue are served by the same passes
		$this->prolongWaiting($launch, $retried + $waiting);
		$this->refreshLaunchStatus($launch);

		return $result->setData(['retriedCount' => $retried]);
	}

	/**
	 * The right to start a process of this type on behalf of the initiator of the group. The element does
	 * not exist before this step, so the question is asked about the type of the document: the item aware
	 * check of bizproc needs a complex document id, which is known only once the item has been created.
	 */
	protected function canStartProcess(Launch $launch): bool
	{
		$documentType = $this->getDocumentType($launch);

		return $documentType !== null && \CBPDocument::canUserOperateDocumentType(
			\CBPCanUserOperateOperation::StartWorkflow,
			$launch->createdById,
			$documentType,
		);
	}

	/**
	 * Item of the smart process the started process runs on, one per row of the group: the selection is
	 * expanded into a process per employee outside the scheme, and the employee of a launch reaches the
	 * process through the data of the start event and not through this item.
	 *
	 * The category, the stage and the title are left to the entity itself, which fills them with the
	 * defaults of the type. Neither the automation of the type nor its workflows are asked to react on the
	 * item appearing: the process of this item is started right after, by the queue itself.
	 */
	protected function createProcessItem(Launch $launch): ?int
	{
		if (!Loader::includeModule('crm'))
		{
			return null;
		}

		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($launch->entityTypeId);
		if ($factory === null)
		{
			return null;
		}

		$item = $factory->createItem();
		$item->setAssignedById($launch->createdById);

		// the author of the item comes from the context and not from a field of its own: the field is
		// read only and the entity fills it with the user of the context, which is nobody on this step
		$context = (new \Bitrix\Crm\Service\Context())
			->setUserId($launch->createdById)
			->setScope(\Bitrix\Crm\Service\Context::SCOPE_TASK)
		;

		$addResult = $factory->getAddOperation($item, $context)
			->disableAllChecks()
			->disableAutomation()
			->disableBizProc()
			->launch()
		;

		return $addResult->isSuccess() ? $item->getId() : null;
	}

	/**
	 * Starts the process of one row and returns the identifier the engine gave it.
	 *
	 * @param string|null $error message of the engine when the process did not start
	 */
	protected function startProcess(Launch $launch, LaunchItem $item, ?string &$error = null): ?string
	{
		$error = null;
		$documentId = \CCrmBizProcHelper::ResolveDocumentId($launch->entityTypeId, (int)$item->entityId);
		if ($documentId === null)
		{
			return null;
		}

		$errors = [];
		$workflowId = \CBPDocument::startWorkflow(
			$launch->templateId,
			$documentId,
			$this->buildStartParameters($launch, $item),
			$errors,
		);
		if (is_string($workflowId) && $workflowId !== '')
		{
			return $workflowId;
		}

		$error = $this->extractFirstErrorMessage($errors);

		return null;
	}

	/**
	 * What the engine gets together with the start. The employee, the initiator and the moment of the
	 * launch travel in the data of the start event (NORMATIVE TPL-02): that is the channel the start node
	 * reads them from, and they are not start parameters of the template. The moment is the one the group
	 * was queued at and not the current one - this step runs later than the press of the button, sometimes
	 * by minutes, and the node promises the initiator the moment of the press.
	 */
	protected function buildStartParameters(Launch $launch, LaunchItem $item): array
	{
		$parameters = $launch->parameters;

		// the surface is the one the fields were collected for: the engine resolves the version of the
		// template by it, and another value would start a version the initiator never filled in
		$parameters[\CBPDocument::PARAM_MANUAL_START_SURFACE] = ManualStartSurface::StartForm->value;
		$parameters[\CBPDocument::PARAM_TRIGGER_EVENT_DATA] = [
			'EmployeeId' => $item->employeeId,
			'InitiatorId' => $launch->createdById,
			'StartedDate' => $launch->queuedAt->format(Main\Type\DateTime::getFormat()),
		];

		return $parameters;
	}

	private function startItem(Launch $launch, LaunchItem $item): void
	{
		// a row returned by a retry already carries the item of the smart process its start failed on, and a
		// second item would be left behind with no process running on it
		$entityId = $item->entityId ?? $this->createProcessItem($launch);
		if ($entityId === null)
		{
			$this->failItem($item, self::REASON_ITEM_NOT_CREATED);

			return;
		}

		$item->entityId = $entityId;
		$error = null;
		$workflowId = $this->startProcess($launch, $item, $error);
		if ($workflowId === null)
		{
			$this->failItem($item, $error ?? self::REASON_NOT_STARTED);

			return;
		}

		$item->workflowId = $workflowId;
		$item->status = LaunchItemStatus::Started;
		$this->launchRepository->updateItem($item);
	}

	private function failItem(LaunchItem $item, string $reason): void
	{
		$item->status = LaunchItemStatus::Error;
		$item->errorMessage = mb_substr($reason, 0, self::ERROR_MESSAGE_LENGTH);
		$this->launchRepository->updateItem($item);
	}

	/**
	 * The reason of the previous failure goes away with the row returning to the queue: it belongs to an
	 * attempt that is over, and a waiting row carrying an error would be shown as a failed one.
	 */
	private function requeueItem(LaunchItem $item): void
	{
		$item->status = LaunchItemStatus::Queued;
		$item->errorMessage = null;
		$this->launchRepository->updateItem($item);
	}

	/**
	 * The waiting term of the group counted from now: the rows returned to the queue are served by the same
	 * passes the term of a fresh group is counted from, so the group must not expire on the term it has
	 * already spent.
	 */
	private function prolongWaiting(Launch $launch, int $waitingCount): void
	{
		$expiresAt = (new DateTime())->withAddSeconds($this->getWaitingTermSeconds($waitingCount));
		$launch->expiresAt = $expiresAt;
		$this->launchRepository->updateExpiresAt($launch->getId(), $expiresAt);
	}

	/**
	 * State of the group derived from its rows. The group owns no state beyond the waiting: once nothing
	 * waits any more the group is finished, and an error of any of its rows makes it the error the
	 * initiator is offered a retry for.
	 */
	private function refreshLaunchStatus(Launch $launch): void
	{
		$hasQueued = false;
		$hasStarted = false;
		$hasError = false;

		foreach ($this->launchRepository->listItems($launch->getId()) as $item)
		{
			$hasQueued = $hasQueued || $item->status === LaunchItemStatus::Queued;
			$hasStarted = $hasStarted || $item->status === LaunchItemStatus::Started;
			$hasError = $hasError || $item->status === LaunchItemStatus::Error;
		}

		if ($hasQueued)
		{
			$status = ($hasStarted || $hasError) ? LaunchStatus::Running : LaunchStatus::Queued;
		}
		else
		{
			$status = $hasError ? LaunchStatus::Error : LaunchStatus::Done;
		}

		if ($status !== $launch->status)
		{
			$launch->status = $status;
			$this->launchRepository->updateStatus($launch->getId(), $status);
		}
	}

	private function getDocumentType(Launch $launch): ?array
	{
		if (!Loader::includeModule('bizproc') || !Loader::includeModule('crm'))
		{
			return null;
		}

		return \CCrmBizProcHelper::ResolveDocumentType($launch->entityTypeId);
	}

	/**
	 * @param array $errors errors of the engine: array{code: string, message: string, file: string}[]
	 */
	private function extractFirstErrorMessage(array $errors): ?string
	{
		$message = trim((string)(reset($errors)['message'] ?? ''));

		return $message === '' ? null : $message;
	}
}
