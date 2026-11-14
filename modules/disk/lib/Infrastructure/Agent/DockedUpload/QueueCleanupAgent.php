<?php
declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Agent\DockedUpload;

use Bitrix\Disk\Controller\DockedUploader\Transport;
use Bitrix\Disk\Infrastructure\Agent\BaseDiskAgent;
use Bitrix\Disk\Infrastructure\Agent\RunResult;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItemCollection;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Model\DockedUploadQueueTable;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadQueueRepository;
use Bitrix\Disk\Internal\Service\DockedUpload\CommitReconciler;
use Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService;
use Bitrix\Disk\Internal\Service\DockedUpload\TempPresenceProbe;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\UI\FileUploader\Uploader;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * TTL garbage collector for stale docked upload queues (24h, synced with the
 * ui.fileuploader temp window, Q-003).
 *
 * It only removes what an abandoned session leaves behind: expired queue/item
 * rows and, for each item still holding a temp token, the temp file (via
 * {@see Uploader::remove()}, whose TempFileTable::onDelete cascade frees the
 * content and the temporary b_file). It never touches a committed
 * DISK_OBJECT_ID (the temp of a committed item became a durable file) and never
 * deletes the empty sub-folders created by createFolderTree (invariant).
 *
 * A temp file is only freed once it is proven to be the queue OWNER's, through the
 * lookup the explicit release paths share ({@see TempPresenceProbe::filterOwnPresent()}):
 * a token on a row is client-written, and remove() honours the GUID inside it without
 * ever asking whose the bytes are. This run walks queues of many users, so ownership is
 * settled per queue rather than over the tokens the run happened to collect.
 *
 * Before deleting anything it reconciles the crash window (ALG-01): an item whose
 * domain write (addFile/addVersion) landed but whose atomic commit did not leaves a
 * durable disk object carrying the intent token while the row is still non-committed -
 * `failed` included, since a crash in that window on a live tab ends there: the client
 * escalates the commit whose answer never arrived and the row is failed while the object
 * exists. The intent-token row is the only key back to that object, so it is driven to
 * committed via {@see FileCommitService::commit()} - firing the durable side effects
 * (BizProc autostart, parity analytics) exactly once - BEFORE the rows are dropped. The
 * read-path {@see \Bitrix\Disk\Public\Provider\DockedUpload\QueueRestoreProvider} heals
 * the same window for the in-flight statuses; as the last backstop this agent also takes
 * the rows the client already gave up on.
 *
 * Explicit cancel/dismiss frees temp files immediately; this agent is only the
 * backstop for the remainder.
 */
class QueueCleanupAgent extends BaseDiskAgent
{
	/** Queues taken per portion, keeping a single query and its work short. */
	protected const QUEUE_BATCH = 20;

	/**
	 * Wall-clock budget of one run. QUEUE_BATCH is the portion size, not the cap of a
	 * run: with one run per hour a per-run cap would put a ceiling on the whole portal's
	 * expired backlog, and everything above it would never be drained - while the rows
	 * pile up inside the IX_DISK_DOCKED_UPLOAD_QUEUE_USER_ID_STATUS range the folder
	 * page walks on every hit.
	 *
	 * Kept moderate on purpose: an agent runs in the background queue of a request that has
	 * already been answered ({@see \Bitrix\Main\Application::terminate()}, past
	 * fastcgi_finish_request), so this budget does not hold up a page - it holds a PHP-FPM
	 * worker out of the pool and puts its reads on the master. Freeing a queue whose rows
	 * still hold temp costs seconds inside the transport, so a backlog is caught up with by
	 * coming back OFTEN ({@see AGENT_MIN_INTERVAL}) rather than by holding one worker longer.
	 *
	 * Measured from the run itself rather than from START_EXEC_TIME: agents are also
	 * processed by a long-lived cron script, whose hit clock is spent long before this
	 * agent's turn.
	 */
	protected const RUN_TIME_LIMIT_SECONDS = 30;

	/**
	 * The two intervals the run keeps its own agent record at: the upper one while the portal
	 * has nothing expired left over, the lower one while it has.
	 *
	 * The upper bound is what the migration registers, so a portal with nothing to clean stays
	 * exactly where it started and pays nothing for the rotation. The lower one is the lever
	 * that actually moves the drain rate: a run frees only so many temp-heavy queues inside
	 * {@see RUN_TIME_LIMIT_SECONDS}, so four runs an hour clear four times the backlog while
	 * each of them still ends quickly. Same shape as TempFileAgent of ui, whose own run moves
	 * its interval between 1800 and 360.
	 */
	protected const AGENT_MAX_INTERVAL = 3600;
	protected const AGENT_MIN_INTERVAL = 900;

	/**
	 * Backoff applied to a queue whose cleanup keeps failing, so an unremovable
	 * head does not starve the rest of the EXPIRES_AT-ordered batch. Larger than
	 * the agent interval (3600s) so a poisoned queue skips at least one run.
	 */
	protected const CLEANUP_RETRY_BACKOFF_SECONDS = 3 * 3600;

	protected LoggerInterface $logger;
	protected DockedUploadQueueRepository $queueRepository;
	protected DockedUploadItemRepository $itemRepository;
	protected Uploader $uploader;
	protected FileCommitService $commitService;
	protected CommitReconciler $reconciler;
	protected TempPresenceProbe $tempPresenceProbe;

	public function __construct()
	{
		$this->logger = LoggerFactory::create(feature: 'docked upload cleanup');
		$this->queueRepository = new DockedUploadQueueRepository();
		$this->itemRepository = new DockedUploadItemRepository();
		$this->uploader = $this->createUploader();
		$this->commitService = $this->createCommitService();
		$this->reconciler = new CommitReconciler();
		$this->tempPresenceProbe = new TempPresenceProbe();
	}

	/**
	 * Recreates the docked temp controller, exactly as include.php wires it.
	 *
	 * The agent does not take the uploader from DI - it builds its own - so this
	 * point and the DI registration must agree, or the agent signs with a foreign
	 * salt and frees nothing. They agree by construction rather than by convention:
	 * both pass an empty array and Transport ignores the argument anyway, so the
	 * controller name and options (the salt inputs) are identical either way.
	 *
	 * Overridable so a test can inject a spy without a live transport.
	 */
	protected function createUploader(): Uploader
	{
		return new Uploader(new Transport([]));
	}

	/**
	 * Takes the shared commit service from DI, exactly as include.php wires it
	 * (disk.dockedUpload.fileCommitService). The reconcile branch never re-materialises
	 * a temp (the durable object already exists), so the agent's own uploader is not
	 * involved here.
	 *
	 * Overridable so a test can inject a spy without a live ServiceLocator state.
	 */
	protected function createCommitService(): FileCommitService
	{
		return ServiceLocator::getInstance()->get('disk.dockedUpload.fileCommitService');
	}

	protected function runInternal(): RunResult
	{
		// Keep the agent periodic regardless of how much it cleaned this run.
		$result = new RunResult(true);

		if (!Application::getConnection()->isTableExists(DockedUploadQueueTable::getTableName()))
		{
			return $result;
		}

		$deadline = $this->now() + static::RUN_TIME_LIMIT_SECONDS;

		// Queues that neither went away nor moved - their cleanup failed AND the backoff
		// bump failed with it. They keep the head of the EXPIRES_AT order, so without this
		// they would be the whole of every following portion of this run.
		$stuckQueueIds = [];

		// Whether the portal still holds expired queues this run did not get to. It decides
		// how soon the agent comes back, so it is read off what the run actually did rather
		// than bought with a COUNT of its own.
		$queuesLeftBehind = false;

		while ($this->now() < $deadline)
		{
			$expired = $this->fetchExpiredQueues($stuckQueueIds);
			if ($expired === [])
			{
				// Nothing expired is left over (bar the stuck ones): caught up.
				$queuesLeftBehind = false;

				break;
			}

			// A portion that came back full is a selection that hit its LIMIT, so there is
			// very likely more behind it - the reading TempFileAgent takes of its own portion.
			// An unfinished portion below replaces this likelihood with certainty.
			$queuesLeftBehind = count($expired) === static::QUEUE_BATCH;

			$progressed = false;
			foreach ($expired as $row)
			{
				if ($this->now() >= $deadline)
				{
					$queuesLeftBehind = true;

					break;
				}

				$queueId = (int)$row['ID'];
				try
				{
					$this->cleanupQueue($queueId, (int)$row['USER_ID']);
					$progressed = true;
				}
				catch (Throwable $exception)
				{
					$this->logger->error('docked cleanup failed for queue', [
						'queueId' => $queueId,
						'message' => $exception->getMessage(),
					]);

					if ($this->deferFailedQueue($queueId))
					{
						$progressed = true;
					}
					else
					{
						$stuckQueueIds[] = $queueId;
					}
				}
			}

			// Neither a deletion nor a deferral: the next portion would be these rows again.
			if (!$progressed)
			{
				// And so would the next run, so there is nothing here for a shorter interval
				// to catch up with: these queues are not a backlog, they are stuck.
				$queuesLeftBehind = false;

				break;
			}
		}

		$this->syncAgentInterval($queuesLeftBehind);

		return $result;
	}

	/**
	 * The wall clock the run budget is measured against.
	 *
	 * Overridable so a test can spend the budget without sitting out real seconds.
	 */
	protected function now(): float
	{
		return microtime(true);
	}

	/**
	 * Keeps the interval of the agent's own record in step with what the run left behind:
	 * minutes while the portal has an expired backlog, an hour once it has none. The record
	 * is the only place the schedule lives and the base agent carries no way to hand a next
	 * interval back, so the run reads and writes it exactly as TempFileAgent of ui does.
	 *
	 * Cheap and best-effort: one read, and a write only when the interval really moves - the
	 * hourly state is also the registered one, so a portal that never builds a backlog never
	 * writes here at all. A registration that is missing or named otherwise simply keeps the
	 * schedule it has: a cleanup run must not start managing agent rows it cannot recognise.
	 */
	protected function syncAgentInterval(bool $queuesLeftBehind): void
	{
		$interval = $queuesLeftBehind ? static::AGENT_MIN_INTERVAL : static::AGENT_MAX_INTERVAL;

		$agent = \CAgent::getList(['ID' => 'DESC'], [
			'MODULE_ID' => static::$moduleId,
			'=NAME' => self::agentName(),
		])->fetch();

		if (!$agent || (int)$agent['AGENT_INTERVAL'] === $interval)
		{
			return;
		}

		$this->writeAgentInterval((int)$agent['ID'], $interval);
	}

	/**
	 * The one write of the rotation.
	 *
	 * Overridable so a test can tell "not written" from "written the same value": CAgent goes
	 * through the legacy $DB, which the connection's own SQL tracker does not see.
	 */
	protected function writeAgentInterval(int $agentId, int $interval): void
	{
		\CAgent::update($agentId, ['AGENT_INTERVAL' => $interval]);
	}

	/**
	 * The name the migration registers this agent under (`disk/install/migrations/agents.php`)
	 * - the concrete class, not whatever subclass happens to be running: a test stand-in acts
	 * for that very registration.
	 */
	private static function agentName(): string
	{
		return self::class . '::run();';
	}

	/**
	 * @param int[] $stuckQueueIds queues this run already failed to remove and failed to defer
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchExpiredQueues(array $stuckQueueIds): array
	{
		$now = new DateTime();
		$query = DockedUploadQueueTable::query()
			->setSelect(['ID', 'USER_ID'])
			->where('EXPIRES_AT', '<', $now)
			->where(
				Query::filter()
					->logic('or')
					->whereNull('NEXT_CLEANUP_AT')
					->where('NEXT_CLEANUP_AT', '<', $now)
			)
			->setOrder(['EXPIRES_AT' => 'ASC'])
			->setLimit(static::QUEUE_BATCH)
		;

		if ($stuckQueueIds !== [])
		{
			$query->whereNotIn('ID', $stuckQueueIds);
		}

		return $query->fetchAll();
	}

	/**
	 * Defers a queue whose cleanup failed, so a poisoned head does not clog the
	 * EXPIRES_AT-ordered portion every run. On success the queue is deleted, so no reset
	 * path is needed. Best-effort: a failed bump is only logged - and reported back, since
	 * a row that neither went away nor moved is what would make a run repeat itself.
	 *
	 * Overridable so a test can reproduce a failed bump without breaking the table.
	 *
	 * @return bool whether the queue left the next portion's selection
	 */
	protected function deferFailedQueue(int $queueId): bool
	{
		try
		{
			DockedUploadQueueTable::update($queueId, [
				'NEXT_CLEANUP_AT' => DateTime::createFromTimestamp(
					time() + static::CLEANUP_RETRY_BACKOFF_SECONDS
				),
			]);

			return true;
		}
		catch (Throwable $bumpException)
		{
			$this->logger->error('docked cleanup backoff bump failed', [
				'queueId' => $queueId,
				'message' => $bumpException->getMessage(),
			]);

			return false;
		}
	}

	private function cleanupQueue(int $queueId, int $userId): void
	{
		$items = $this->itemRepository->getByQueue($queueId, $userId);

		// B3: reconcile the crash window BEFORE dropping the rows - a durable object left
		// by a domain write that never reached its atomic commit is finalized (exactly
		// once) so its side effects fire instead of being lost with the intent-token row.
		$this->reconcileDurableItems($items);

		// One temp release batch, then one DELETE for the whole queue's items. The tokens are
		// proven to be the QUEUE OWNER's live bytes first: remove() deletes a temp row by the
		// GUID inside the token and never compares CREATED_BY, while the token on a row is
		// whatever the client wrote there. This run walks queues of different users, so the
		// proof is asked per queue owner and never over the pooled tokens of a run.
		$this->releaseTemp(
			$this->tempPresenceProbe->filterOwnPresent($this->collectTempTokens($items), $userId)
		);
		$this->itemRepository->deleteByQueue($queueId);

		$this->queueRepository->delete($queueId);

		$this->logger->info('docked queue expired and cleaned', [
			'queueId' => $queueId,
		]);
	}

	/**
	 * Drives the guarded finalize for every item that still carries an intent token
	 * and whose durable disk object/version already exists (crash between the domain
	 * write and the atomic commit). Mirrors the read-path reconciliation of
	 * QueueRestoreProvider::reconcileDurableItems over the SAME candidate statuses
	 * ({@see ItemStatus::isDurableReconcilable()}) and minus the temp-validity gate the
	 * cleanup path does not need: batch-probe by intent token, then commit() only the
	 * durable-confirmed ones. commit() is guarded and exactly-once - an already
	 * committed item short-circuits, a durable-but-uncommitted one is carried to
	 * committed with its side effects, and an item with no durable object is left
	 * untouched (the commit gate is never re-opened).
	 */
	private function reconcileDurableItems(DockedUploadItemCollection $items): void
	{
		/** @var DockedUploadItem[] $candidates */
		$candidates = [];
		$fileTokens = [];
		$versionTokens = [];

		foreach ($items as $item)
		{
			if (!$this->isReconcileCandidate($item))
			{
				continue;
			}

			$intentToken = (string)$item->getIntentToken();
			$candidates[] = $item;
			if ($this->isVersionBranch($item))
			{
				$versionTokens[] = $intentToken;
			}
			else
			{
				$fileTokens[] = $intentToken;
			}
		}

		if ($candidates === [])
		{
			return;
		}

		$durableFileTokens = $this->reconciler->findExistingFileIntentTokens($fileTokens);
		$durableVersionTokens = $this->reconciler->findExistingVersionIntentTokens($versionTokens);

		foreach ($candidates as $item)
		{
			$intentToken = (string)$item->getIntentToken();
			$hasDurable = $this->isVersionBranch($item)
				? isset($durableVersionTokens[$intentToken])
				: isset($durableFileTokens[$intentToken])
			;

			if ($hasDurable)
			{
				$this->commitService->commit($item);
			}
		}
	}

	/**
	 * An item is a reconcile candidate when it carries an intent token - the proof that a
	 * commit ran for it at all, since nothing but
	 * {@see \Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService} ever mints one -
	 * and its status admits the finalize ({@see ItemStatus::isDurableReconcilable()}, which
	 * owns the membership and explains its composition).
	 *
	 * The status set is shared with the restore path verbatim: the reconciliation is one
	 * mechanism running in two places, so a row must not be a candidate here and a bystander
	 * there. What stays local to this path is the absence of a temp gate (see
	 * {@see self::reconcileDurableItems()}). A row whose stored status does not parse is no
	 * candidate: the finalize carries durable side effects, and they are not spent on a state
	 * this code cannot name.
	 */
	private function isReconcileCandidate(DockedUploadItem $item): bool
	{
		if ((string)$item->getIntentToken() === '')
		{
			return false;
		}

		return $item->getStatus()?->isDurableReconcilable() === true;
	}

	private function isVersionBranch(DockedUploadItem $item): bool
	{
		$targetFileId = $item->getTargetFileId();

		return $targetFileId !== null && $targetFileId > 0;
	}

	/**
	 * Collects the leftover temp tokens of non-committed items. A committed
	 * item's temp has already become a durable b_file, so it is deliberately
	 * skipped.
	 *
	 * @return string[]
	 */
	private function collectTempTokens(DockedUploadItemCollection $items): array
	{
		$tokens = [];
		foreach ($items as $item)
		{
			$token = (string)$item->getTempToken();
			if ($token === '' || $item->getStatus() === ItemStatus::Committed)
			{
				continue;
			}

			$tokens[] = $token;
		}

		return $tokens;
	}

	/**
	 * Frees the collected leftover temp files with a single Uploader::remove()
	 * call for the whole queue.
	 *
	 * @param string[] $tokens the queue owner's tokens whose bytes are still in temp
	 *   ({@see TempPresenceProbe::filterOwnPresent()}). A foreign token, or one whose temp
	 *   is already gone, never gets here: the transport is not asked about it, and the rows
	 *   are dropped all the same - there is nothing left for a retry to free.
	 */
	private function releaseTemp(array $tokens): void
	{
		if ($tokens === [])
		{
			return;
		}

		// remove() does not throw - it reports a per-token outcome - so an unfreed temp
		// has to be read off the result. A token whose temp row is already gone comes
		// back successful (the delete branch simply does not run), which is the wanted
		// idempotency of a repeated cleanup and never an error.
		$failedCodes = [];
		$failedCount = 0;
		foreach ($this->uploader->remove($tokens) as $result)
		{
			if ($result->isSuccess())
			{
				continue;
			}

			$failedCount++;
			foreach ($result->getErrors() as $error)
			{
				$failedCodes[] = (string)$error->getCode();
			}
		}

		if ($failedCount === 0)
		{
			return;
		}

		// cleanupQueue must NOT delete the queue rows when the temp was not freed, or
		// the temp leaks with no row left to retry from. Raising here hands the queue to
		// the per-queue catch in runInternal(), which logs it and keeps the rows, so the
		// next run retries this queue. Tokens stay out of the message: they are
		// capabilities, not metadata.
		throw new \RuntimeException(sprintf(
			'could not free %d of %d temp files (%s)',
			$failedCount,
			count($tokens),
			implode(', ', array_unique($failedCodes)),
		));
	}
}
