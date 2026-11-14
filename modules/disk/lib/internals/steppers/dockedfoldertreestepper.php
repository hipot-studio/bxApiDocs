<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internals\Steppers;

use Bitrix\Disk\Internal\Dto\DockedUpload\ItemDto;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository;
use Bitrix\Disk\Internal\Repository\Interface\DockedUploadQueueRepositoryInterface;
use Bitrix\Disk\Internal\Service\DockedUpload\DisplayStatusResolver;
use Bitrix\Disk\Internal\Service\DockedUpload\FolderTreeBuilder;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueChangeProjector;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueEventNotifier;
use Bitrix\Disk\Internal\Service\Logger\ExceptionToContextConverter;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Security\Random;
use Bitrix\Main\SystemException;
use Bitrix\Main\Update\Stepper;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Portioned creation of a docked folder tree for large batches (API-04).
 *
 * The command builds small trees inline; a big one is enqueued here and built in
 * PORTION-sized slices. {@see FolderTreeBuilder} is idempotent, so resuming a job
 * with the accumulated folder map never duplicates folders. When a job is fully
 * built, the queued file items are bound to their folders. Empty sub-folders are
 * never removed on interruption (invariant).
 *
 * A tick runs slices until its wall-clock budget ({@see TICK_TIME_LIMIT_SECONDS}) is spent,
 * and every slice hands the turn on to the next user ({@see passTurn()}): the tokens of the
 * user just served move to the tail of the index, so a portal-wide wave of folder uploads is
 * shared out between its users instead of being run off strictly in the order it arrived.
 * The user of a job is carried by the token itself, so choosing the next one never reads a
 * job payload.
 *
 * Each job lives in its own per-token Option ({@see OPTION_JOB_PREFIX}); a small
 * shared index Option ({@see OPTION_INDEX}) only holds the active tokens. This
 * keeps the jobs of different users/trees off a single shared value (no lost
 * update, no starvation) and lets the serialized payload shrink as portions are
 * processed instead of growing O(N^2). Index mutations run under a named DB lock.
 *
 * The two live in categories of their own, and in DIFFERENT ones. Option is
 * category-granular in both directions - `Option::load()` selects every row of the
 * category into a static array and the managed cache, `Option::set()` clears that
 * cache whole - so anything sharing a category with the job payloads is re-read in
 * full whenever one of them is written. That is why the jobs left `main.stepper.disk`
 * (which the base Stepper reads and writes on every tick of every disk stepper), and
 * why the index then left the job category too: a tick runs several portions, each
 * saving its job, and the index would have been re-read through megabytes of payloads
 * once per portion - and once more under the global lock on the enqueue path.
 * State left in any former home is adopted at the start of every run
 * ({@see adoptLegacyState()}): jobs from the plain `disk` options and from
 * `main.stepper.disk`, the index from the job category. The lock name predates every
 * one of those moves, so all code versions serialize on it during a rolling update.
 */
final class DockedFolderTreeStepper extends Stepper
{
	public const PORTION = 200;

	private const OPTION_CATEGORY = 'disk.docked_folder_tree';
	/**
	 * Home of the token index alone, kept apart from the job payloads so that reading it
	 * costs a few kilobytes rather than every active job of the portal, and so that saving a
	 * job does not invalidate it. It is also the category a run reads under the index lock,
	 * which is what the enqueue path waits on.
	 */
	private const OPTION_INDEX_CATEGORY = 'disk.docked_folder_tree_index';
	// Former homes of the jobs, oldest first: the real `disk` module options, then the
	// state category the base Stepper shares between all disk steppers.
	private const LEGACY_OPTION_CATEGORIES = ['disk', 'main.stepper.disk'];
	private const OPTION_INDEX = 'docked_folder_tree_index';
	private const OPTION_JOB_PREFIX = 'dft_job_';
	private const TOKEN_PREFIX = 'dft';
	/**
	 * What separates the user of a job from the random part of its token. Never produced by
	 * {@see Random::getString()} (digits and lower-case letters only), so a token of a version
	 * that carried no user is told apart by its absence rather than by a version mark.
	 */
	private const TOKEN_USER_SEPARATOR = '_';
	private const LOCK_TIMEOUT = 10;
	private const REGISTER_LOCK_ATTEMPTS = 3;
	/**
	 * Wall-clock budget of one tick, checked BETWEEN slices: the first one always runs and a
	 * single slice may overrun the budget, the same trade QueueCleanupAgent makes with its
	 * queues.
	 *
	 * Short on purpose. Agents run in the background queue of a request that has already been
	 * answered ({@see \Bitrix\Main\Application::terminate()}, past fastcgi_finish_request),
	 * so what this budget spends is not a user waiting for a page but a PHP-FPM worker held
	 * out of the pool and load on the master. One slice of 200 created folders costs up to
	 * ~7.4s and the tail of a job usually far less; five seconds buys the several short slices
	 * the rotation needs to reach every user of a folder wave, and keeps a tick well under the
	 * threshold at which {@see Stepper::execAgent()} starts stretching the agent interval.
	 */
	private const TICK_TIME_LIMIT_SECONDS = 5;
	/**
	 * How long a job may keep failing before its files are given the terminal verdict and
	 * its token leaves the index.
	 *
	 * Wall-clock seconds, not runs: enqueue binds the agent with a one-second interval, so
	 * "three runs in a row" is a three-second window - a burst of deadlocks on b_disk_object
	 * or a lock storm on a replica would close a big folder upload the user would then have
	 * to drag in again. Patience costs almost nothing here: the rotation below already lets
	 * the jobs behind this one through, so the only price of waiting is how late the owner
	 * of a truly broken job hears about it. Ten minutes is the order the platform itself
	 * uses for a run that died ({@see \CAgent::LOCK_TIME}), long enough to sit out a
	 * transient and far short of the 24h queue TTL its leaves would otherwise wait for.
	 */
	private const GIVE_UP_AFTER_SECONDS = 600;

	protected static $moduleId = 'disk';

	private ?LoggerInterface $logger = null;

	/**
	 * @param Closure|null $clock reads the wall clock the tick budget is measured against.
	 *        The platform builds the stepper with {@see Stepper::createInstance()} and gets
	 *        microtime(); a test drives the budget through its own.
	 */
	public function __construct(private readonly ?Closure $clock = null)
	{
	}

	/**
	 * Registers a job and schedules the stepper.
	 *
	 * @param array{userId: int, rootFolderId: int, folderItemId: int, relativePaths: string[]} $job
	 * @return string the stepper token identifying the job
	 * @throws SystemException when the token cannot be registered (job is not queued)
	 */
	public static function enqueue(array $job): string
	{
		$userId = (int)$job['userId'];
		// The user rides in the token so the rotation can group the index by owner without
		// unserializing a payload per entry. The index itself keeps the shape every version
		// of this class reads - a plain list of tokens - so a rolling update cannot make one
		// side drop what the other registered.
		$token = self::TOKEN_PREFIX . $userId . self::TOKEN_USER_SEPARATOR . Random::getString(24);

		self::saveJob($token, [
			'userId' => $userId,
			'rootFolderId' => (int)$job['rootFolderId'],
			'folderItemId' => (int)$job['folderItemId'],
			'relativePaths' => array_values($job['relativePaths']),
			'offset' => 0,
			'folderMap' => [],
			'failedPaths' => [],
		]);

		// The job value is only reachable through the index, so the token MUST land
		// there - the durable point of enqueue is a registered token, not the saved
		// job. If registration fails, drop the orphan job and surface the failure so
		// the caller reports an error instead of a success for a job that will never
		// run (execute() only reads jobs whose token is in the index).
		try
		{
			self::registerToken($token);
		}
		catch (SystemException $exception)
		{
			Option::delete(self::OPTION_CATEGORY, ['name' => self::jobOptionName($token)]);

			throw $exception;
		}

		self::bind(1);

		return $token;
	}

	public function execute(array &$result): bool
	{
		$legacyAdopted = self::adoptLegacyState();
		$deadline = $this->now() + self::TICK_TIME_LIMIT_SECONDS;

		// Tokens this tick is done with, whatever the reason: the portion threw, or the entry
		// was stale and its removal is all there was to do. Both step aside for their
		// neighbours, and nothing stops the rotation from offering them back before the tick
		// is over - a job that cannot move at all would then spend the whole budget failing,
		// logging and re-reading its own record once per portion instead of once per tick.
		// Same role the stuck queues play in QueueCleanupAgent's run.
		$settledTokens = [];

		do
		{
			// One read of the index per portion: it picks the job AND decides the rotation
			// afterwards, so passTurn() is handed this list instead of reading it again.
			$tokens = self::loadIndex();

			$token = self::nextToken($tokens, $settledTokens);
			if ($token === null)
			{
				break;
			}

			$job = self::loadJob($token);
			if ($job === null)
			{
				// Stale index entry (job value already gone) - drop it and move on.
				self::finishJob($token);
				$settledTokens[] = $token;

				continue;
			}

			// An exception escaping the portion would otherwise hand this very job to every
			// following run and no other job would ever be reached - one broken tree would
			// hold the trees of the whole portal. CAgent only writes the throw to the log
			// (and leaves the agent row RUNNING), so nothing above moves the job aside
			// either. Isolate it here, the way QueueCleanupAgent isolates a queue whose
			// cleanup keeps failing.
			$demotedToken = null;
			try
			{
				$this->runPortion($token, $job, $result);
			}
			catch (Throwable $exception)
			{
				$this->isolateFailedJob($token, $job, $exception);
				$settledTokens[] = $token;
				$demotedToken = $token;
			}

			self::passTurn($tokens, self::tokenUserId($token), $demotedToken);
		}
		while ($this->now() < $deadline);

		return self::finishWhenIdle($legacyAdopted);
	}

	/**
	 * The wall clock of the tick budget.
	 */
	private function now(): float
	{
		return $this->clock === null ? microtime(true) : ($this->clock)();
	}

	/**
	 * One portion of one job. Every branch that ends the job removes its token itself, so
	 * the caller is only left with the portion throwing.
	 *
	 * @param array<string, mixed> $job
	 * @param array<string, mixed> $result
	 */
	private function runPortion(string $token, array $job, array &$result): void
	{
		// The upload may have been called off while the job waited for its turn, and the
		// folders this portion would create are never removed afterwards (invariant), so
		// the state is re-read before every portion, not once at enqueue.
		if ($this->isAbandoned((int)($job['folderItemId'] ?? 0)))
		{
			$this->finishWithoutBuilding($token, $job, rootIsGone: false);

			return;
		}

		// A job is data read back from an Option, not a value this run built: a record
		// truncated or written by a version that named its keys differently must not turn
		// into a TypeError no later run can get past. A saved job always keeps at least one
		// path left to place (the last portion binds and finishes within its own run), so
		// nothing left here means the record is unusable - and an unusable record owes its
		// leaves the same verdict a vanished root does, never a silent drop.
		$relativePaths = self::jobList($job, 'relativePaths');
		if ($relativePaths === [])
		{
			$this->getLogger()->error('docked folder tree job carries no paths', ['token' => $token]);
			$this->finishWithFailureVerdict($token, $job);

			return;
		}

		$userId = (int)($job['userId'] ?? 0);
		$builder = $this->getBuilder();

		// S1 (TOCTOU): re-verify the root is still writable for this user before
		// mutating anything. If it vanished or access was revoked between enqueue
		// and now, no branch of this sub-tree can ever exist - a terminal verdict.
		$root = $builder->resolveWritableRoot((int)($job['rootFolderId'] ?? 0), $userId);
		if ($root === null)
		{
			$this->finishWithoutBuilding($token, $job, rootIsGone: true);

			return;
		}

		$slice = array_slice($relativePaths, 0, self::PORTION);
		$tree = $builder->buildTree($root, $slice, $userId, self::jobList($job, 'folderMap'));

		$job['folderMap'] = $tree['folderMap'];
		// Accumulate failed branches across portions: bindFileItems runs once at the
		// end and must see every branch that failed in any earlier step, or a file of
		// an early-failed branch would look "not built yet" and never be marked failed.
		$job['failedPaths'] = array_values(array_unique(array_merge(
			self::jobList($job, 'failedPaths'),
			$tree['failedPaths'],
		)));
		$job['offset'] = (int)($job['offset'] ?? 0) + count($slice);
		// Drop the paths just processed so the serialized payload shrinks with each
		// portion instead of re-serializing the whole batch every time (PM3).
		$job['relativePaths'] = array_slice($relativePaths, count($slice));

		if ($job['relativePaths'] === [])
		{
			// The root is already loaded, so its storage is inherited by the files of the
			// tree for free - a file whose own target vanished at enqueue would otherwise
			// reach the client with no storage to read its BizProc set by.
			$rootStorageId = (int)$root->getStorageId();
			$bound = $builder->bindFileItems(
				(int)($job['folderItemId'] ?? 0),
				$userId,
				(int)$root->getId(),
				$job['folderMap'],
				$job['failedPaths'],
				$rootStorageId > 0 ? $rootStorageId : null,
			);
			$this->deliverVerdict(
				(int)($job['folderItemId'] ?? 0),
				$userId,
				$bound['failedItemIds'],
				array_column($bound['bindings'], 'itemId'),
			);
			self::finishJob($token);
		}
		else
		{
			// A portion that got through closes the failure streak: the window guards
			// against a job that cannot move at all, not against a bad minute.
			unset($job['failedSince']);
			self::saveJob($token, $job);
		}

		$result = [];
	}

	/**
	 * A job whose portion threw. It holds the head of the index, so leaving it where it is
	 * would make every following run repeat this very failure and reach no other job; the
	 * caller demotes the token behind its neighbours ({@see passTurn()}) as soon as this
	 * returns - that alone is the isolation. Once the streak outlives
	 * {@see GIVE_UP_AFTER_SECONDS} the job is ended for good: the files of a tree that cannot
	 * be built must not sit at "queued" until the queue expires a day later.
	 *
	 * @param array<string, mixed> $job the job as it was loaded - the portion mutated a copy
	 */
	private function isolateFailedJob(string $token, array $job, Throwable $exception): void
	{
		$now = time();
		$failingSince = self::failureStreakStart($job, $now);

		$this->getLogger()->error(
			'docked folder tree job failed',
			[
				'token' => $token,
				'failingForSeconds' => $now - $failingSince,
			] + ExceptionToContextConverter::convert($exception),
		);

		if ($now - $failingSince < self::GIVE_UP_AFTER_SECONDS)
		{
			// Only the failure that OPENS the streak writes the job. A payload runs up to
			// ~500 KB and `Option::set()` clears the cache of the whole category, so a write
			// per tick would have the next read of the category re-select every job of the
			// portal - the very cost the jobs were moved into their own category to avoid.
			// Nothing else in the payload changes while the streak lasts, and how long it
			// has lasted is written in the log, not counted in the job.
			if ((int)($job['failedSince'] ?? 0) !== $failingSince)
			{
				$job['failedSince'] = $failingSince;
				self::saveJob($token, $job);
			}

			return;
		}

		try
		{
			$this->finishWithFailureVerdict($token, $job);
		}
		catch (Throwable $verdictException)
		{
			// The verdict is best-effort; the token leaving the index is not.
			$this->getLogger()->error(
				'docked folder tree verdict failed',
				['token' => $token] + ExceptionToContextConverter::convert($verdictException),
			);
			self::finishJob($token);
		}
	}

	/**
	 * When the current failure streak began. No mark means this failure opens it.
	 *
	 * A mark ahead of the clock is not one any run could have written, so it says the clock
	 * moved back under us (an NTP correction, a host with a wrong timezone in a cluster).
	 * Trusting it would keep a broken job alive for as long as the jump lasts, so the window
	 * restarts from now instead: the job outlives its verdict by one window at most, and
	 * never by the size of the jump.
	 *
	 * @param array<string, mixed> $job
	 */
	private static function failureStreakStart(array $job, int $now): int
	{
		$since = (int)($job['failedSince'] ?? 0);

		return ($since > 0 && $since <= $now) ? $since : $now;
	}

	private function getLogger(): LoggerInterface
	{
		return $this->logger ??= LoggerFactory::create(feature: 'docked folder tree');
	}

	private static function finishWhenIdle(bool $legacyAdopted): bool
	{
		// Unadopted legacy jobs must keep the agent alive: adoption is retried at
		// the start of the next run.
		return (self::hasTokens() || !$legacyAdopted)
			? self::CONTINUE_EXECUTION
			: self::FINISH_EXECUTION;
	}

	private function getBuilder(): FolderTreeBuilder
	{
		return ServiceLocator::getInstance()->get('disk.dockedUpload.folderTreeBuilder');
	}

	/**
	 * Whether the upload this job belongs to was called off while the job waited: the
	 * folder-item is gone or canceled (the queue-wide cancel and the folder's own one both
	 * mark the structural row), or the queue was closed for good.
	 *
	 * Deliberately NOT "the queue is no longer restorable": a queue legitimately reaches
	 * completed through the AC-077 drain, which a neighbouring synchronous chunk of this
	 * very tree may perform while this job is still running - gating on that would cut a
	 * live tree short.
	 */
	private function isAbandoned(int $folderItemId): bool
	{
		$folderItem = (new DockedUploadItemRepository())->getById($folderItemId);
		if ($folderItem === null || $folderItem->getStatus() === ItemStatus::Canceled)
		{
			return true;
		}

		$queue = ServiceLocator::getInstance()
			->get(DockedUploadQueueRepositoryInterface::class)
			->getById((int)$folderItem->getQueueId())
		;

		return $queue === null || $queue->getStatus() === QueueStatus::Dismissed;
	}

	/**
	 * The two ways a portion ends before it builds anything, and they end differently.
	 *
	 * The upload was called off: its elements are already terminal, so there is no verdict
	 * to pass on and the job simply goes away. The root is gone: the files of the sub-tree
	 * can never be placed, and that is what {@see finishWithFailureVerdict()} ends them with.
	 *
	 * @param array<string, mixed> $job
	 */
	private function finishWithoutBuilding(string $token, array $job, bool $rootIsGone): void
	{
		if ($rootIsGone)
		{
			$this->finishWithFailureVerdict($token, $job);

			return;
		}

		self::finishJob($token);
	}

	/**
	 * The end of a job whose files can never be placed - the root is gone, the record is
	 * unusable, or the portion keeps throwing. All three earn the same terminal verdict a
	 * branch failure produces (docked_folder_tree_failed), and here the pull event is the
	 * only way to say so, the caller having been answered with a stepperToken long ago.
	 * Without it the leaves stay at "queued" until the queue expires a day later.
	 *
	 * @param array<string, mixed> $job
	 */
	private function finishWithFailureVerdict(string $token, array $job): void
	{
		$folderItemId = (int)($job['folderItemId'] ?? 0);
		$userId = (int)($job['userId'] ?? 0);

		$this->deliverVerdict(
			$folderItemId,
			$userId,
			$this->getBuilder()->failUnplacedFiles($folderItemId, $userId),
		);

		self::finishJob($token);
	}

	/**
	 * The end of the job is where the async branch reports what it decided, mirroring
	 * {@see \Bitrix\Disk\Public\Command\DockedUpload\CreateFolderTreeCommand::deliverVerdict()}.
	 *
	 * Three verdicts land here and all of them are the server's own:
	 *  - AC-077: a folder upload carrying no file gets no commit to end its queue, so the
	 *    finished tree is the moment it drains. The transition is guarded and re-derives
	 *    the drain from the file leaves, so a folder that does carry files is untouched.
	 *  - the files of a branch that could not be created (docked_folder_tree_failed).
	 *  - the plain success: the tree stands and its leaves are bound, waiting to be sent.
	 *
	 * Unlike the sync branch there is no response to carry any of them: the caller was
	 * answered with a stepperToken long ago, so the pull event is the ONLY channel - which
	 * is why the third one is here at all. The sync branch may skip it (API-04 hands the
	 * built tree back in its own answer), and skipping it here left a build that succeeded
	 * publishing nothing whatsoever: the folder sat at "analyzing" until the user reloaded.
	 * The bound leaves are what makes the projection rebuild the folder rows - they are the
	 * elements whose ancestor chain the change ran through - while failedItemIds stays what
	 * it says, the leaves shipped as failed file DTOs.
	 *
	 * @param int[] $failedItemIds
	 * @param int[] $boundItemIds leaves this build bound to their real target folder
	 */
	private function deliverVerdict(
		int $folderItemId,
		int $userId,
		array $failedItemIds,
		array $boundItemIds = [],
	): void
	{
		$itemRepository = new DockedUploadItemRepository();
		$folderItem = $itemRepository->getById($folderItemId);
		if ($folderItem === null)
		{
			return;
		}

		$queueId = (int)$folderItem->getQueueId();
		$queueRepository = ServiceLocator::getInstance()->get(DockedUploadQueueRepositoryInterface::class);
		$completed = $queueRepository->markCompletedIfDrained($queueId);
		if (!$completed && $failedItemIds === [] && $boundItemIds === [])
		{
			return;
		}

		$queue = $queueRepository->getById($queueId);
		if ($queue === null)
		{
			return;
		}
		if ($completed)
		{
			// The guarded UPDATE moved the row; the entity was read before it, so bring it
			// in line before the projection composes the display layer against it.
			$queue->setStatus(QueueStatus::Completed);
		}
		$queueStatus = $queue->getStatus() ?? QueueStatus::Active;

		/** @var DisplayStatusResolver $resolver */
		$resolver = ServiceLocator::getInstance()->get('disk.dockedUpload.displayStatusResolver');

		// Re-read rather than trust an in-memory copy: updateStatusByIds guards terminal
		// rows out of the UPDATE, so an item that raced to committed/canceled kept its own
		// state (same reasoning as the sync branch).
		$failedItems = [];
		foreach ($itemRepository->getByIds($failedItemIds, $userId) as $item)
		{
			$failedItems[] = ItemDto::fromFileItem($item, $queueStatus, $resolver)->toArray();
		}

		$projection = (new QueueChangeProjector($itemRepository, $resolver))
			->project($queue, $userId, array_values(array_unique(array_merge($failedItemIds, $boundItemIds))))
		;

		/** @var QueueEventNotifier $notifier */
		$notifier = ServiceLocator::getInstance()->get('disk.dockedUpload.queueEventNotifier');
		$notifier->emit(
			$userId,
			(string)$queueId,
			$queueStatus->value,
			array_merge($failedItems, $projection['folderItems']),
			$projection['queueActions'],
			$projection['queueQuota'],
			$projection['queueDisplayStatus'],
		);
	}

	// --- per-token job storage -------------------------------------------

	private static function jobOptionName(string $token): string
	{
		return self::OPTION_JOB_PREFIX . $token;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function loadJob(string $token): ?array
	{
		$raw = Option::get(self::OPTION_CATEGORY, self::jobOptionName($token), '');
		if ($raw === '')
		{
			return null;
		}

		$job = @unserialize($raw, ['allowed_classes' => false]);

		return is_array($job) ? $job : null;
	}

	/**
	 * A list stored inside a job value. The job comes back from an Option, so a key an
	 * older version never wrote - or a truncated record - has to read as "empty" instead
	 * of reaching a parameter typed `array`.
	 *
	 * @param array<string, mixed> $job
	 * @return array<mixed>
	 */
	private static function jobList(array $job, string $key): array
	{
		$value = $job[$key] ?? null;

		return is_array($value) ? $value : [];
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private static function saveJob(string $token, array $job): void
	{
		Option::set(self::OPTION_CATEGORY, self::jobOptionName($token), serialize($job));
	}

	private static function finishJob(string $token): void
	{
		// Only what is really there is deleted. A DELETE of an option that does not exist
		// still costs a statement and, worse, drops the cached category - so a stale index
		// entry, whose payload is gone already, would make the next portion re-read every
		// job of the portal to find its own. The read that replaces it is warm.
		if (Option::get(self::OPTION_CATEGORY, self::jobOptionName($token), '') !== '')
		{
			Option::delete(self::OPTION_CATEGORY, ['name' => self::jobOptionName($token)]);
		}
		// An adoption pass interrupted between copy and cleanup can leave a legacy
		// copy behind; drop it so a later pass cannot resurrect a finished job.
		foreach (self::LEGACY_OPTION_CATEGORIES as $category)
		{
			if (Option::get($category, self::jobOptionName($token), '') !== '')
			{
				Option::delete($category, ['name' => self::jobOptionName($token)]);
			}
		}
		self::unregisterToken($token);
	}

	// --- shared token index ----------------------------------------------

	/**
	 * @return string[]
	 */
	private static function loadIndex(): array
	{
		return self::decodeTokens(Option::get(self::OPTION_INDEX_CATEGORY, self::OPTION_INDEX, ''));
	}

	/**
	 * @return string[]
	 */
	private static function decodeTokens(string $raw): array
	{
		if ($raw === '')
		{
			return [];
		}

		$tokens = @unserialize($raw, ['allowed_classes' => false]);

		return is_array($tokens) ? array_values(array_filter($tokens, 'is_string')) : [];
	}

	/**
	 * @param string[] $tokens
	 */
	private static function saveIndex(array $tokens): void
	{
		if ($tokens === [])
		{
			Option::delete(self::OPTION_INDEX_CATEGORY, ['name' => self::OPTION_INDEX]);

			return;
		}

		Option::set(self::OPTION_INDEX_CATEGORY, self::OPTION_INDEX, serialize(array_values($tokens)));
	}

	/**
	 * The job this tick serves next: the head of the index, which the rotation keeps pointed
	 * at the user whose turn it is.
	 *
	 * @param string[] $tokens the index as this portion read it
	 * @param string[] $skipTokens tokens this tick is already done with
	 */
	private static function nextToken(array $tokens, array $skipTokens): ?string
	{
		foreach ($tokens as $token)
		{
			if (!in_array($token, $skipTokens, true))
			{
				return $token;
			}
		}

		return null;
	}

	/**
	 * The user of a job, as written into its token by {@see enqueue()}. Tokens of a version
	 * that carried no user read as 0 and share one turn between them - a handful of jobs
	 * enqueued right before an update, never a lasting state.
	 */
	private static function tokenUserId(string $token): int
	{
		$parts = explode(self::TOKEN_USER_SEPARATOR, substr($token, strlen(self::TOKEN_PREFIX)), 2);

		return count($parts) === 2 && preg_match('/^[0-9]+$/D', $parts[0]) === 1 ? (int)$parts[0] : 0;
	}

	private static function hasTokens(): bool
	{
		return self::loadIndex() !== [];
	}

	/**
	 * @throws SystemException when the lock stays unavailable across all attempts
	 */
	private static function registerToken(string $token): void
	{
		// Registration is the durable point of enqueue: unlike an unregister (safely
		// retried next tick), a lost registration strands the job forever. Retry the
		// locked read-modify-write a bounded number of times; if the lock still can't
		// be taken, raise so enqueue can drop the orphan job and report the failure.
		$registered = self::mutateIndex(
			static function (array $tokens) use ($token): array {
				if (!in_array($token, $tokens, true))
				{
					$tokens[] = $token;
				}

				return $tokens;
			},
			self::REGISTER_LOCK_ATTEMPTS,
		);

		if (!$registered)
		{
			throw new SystemException('Failed to register a docked folder tree job: index lock unavailable.');
		}
	}

	/**
	 * Hands the turn on: every token of the user just served moves to the tail of the index,
	 * keeping the order that user's own jobs were enqueued in. The head is therefore a job of
	 * a DIFFERENT user whenever the index holds one, which is the whole of the rotation -
	 * there is no cursor to keep in step with the index, and because the order IS the state
	 * it outlives the tick that wrote it. No user waits indefinitely either: each reaches the
	 * tail once per portion, so the job at the head of a user's own group advances within as
	 * many portions as there are other users in the index, and that user's remaining jobs
	 * follow it in the order they were enqueued.
	 *
	 * A failed token is demoted inside its own group as well, so a job the portion threw on
	 * does not hold the jobs its owner queued behind it until the give-up window closes.
	 *
	 * Nothing to move means nothing to write - one user in the index, which is what a portal
	 * that is not under a folder wave looks like - and the decision costs no read of its own:
	 * the list is the one the portion picked its job from. That list may be a token out of
	 * date if the portion finished its job, which can only make a rotation be written where
	 * it would have changed nothing; the mutation itself is recomputed from the current index
	 * under the lock, so what lands is right either way. Deliberately unlocked for the same
	 * reason it always was: a token registered right after the read merely costs its job one
	 * portion at the head, and a rotation lost to lock contention is as harmless as a lost
	 * unregister - the next portion rotates again.
	 *
	 * @param string[] $tokens the index as the portion read it
	 */
	private static function passTurn(array $tokens, int $userId, ?string $demotedToken = null): void
	{
		if (self::rotated($tokens, $userId, $demotedToken) === $tokens)
		{
			return;
		}

		self::mutateIndex(
			static fn(array $current): array => self::rotated($current, $userId, $demotedToken)
		);
	}

	/**
	 * @param string[] $tokens
	 * @return string[]
	 */
	private static function rotated(array $tokens, int $userId, ?string $demotedToken): array
	{
		$waiting = [];
		$served = [];
		$demoted = [];

		foreach ($tokens as $token)
		{
			if ($token === $demotedToken)
			{
				$demoted[] = $token;
			}
			elseif (self::tokenUserId($token) === $userId)
			{
				$served[] = $token;
			}
			else
			{
				$waiting[] = $token;
			}
		}

		return array_merge($waiting, $served, $demoted);
	}

	private static function unregisterToken(string $token): void
	{
		// A skipped unregister is harmless: the token stays in the index and its
		// unregister is retried on the next run, so a single locked attempt is enough.
		self::mutateIndex(
			static fn(array $tokens): array => array_values(
				array_filter($tokens, static fn($t) => $t !== $token)
			)
		);
	}

	/**
	 * Read-modify-write of the shared token index under a named DB lock, so a
	 * concurrent enqueue (web) and a stepper run (agent) cannot clobber each
	 * other's changes to the active-token set.
	 *
	 * @param callable(string[]): string[] $mutator
	 * @param int $attempts how many times to try acquiring the lock before giving up
	 * @return bool whether the mutation was applied (false only on lock contention)
	 */
	private static function mutateIndex(callable $mutator, int $attempts = 1): bool
	{
		$connection = Application::getConnection();

		for ($attempt = 0; $attempt < $attempts; $attempt++)
		{
			// Contention/timeout: an unlocked read-modify-write would reintroduce the
			// lost update the lock guards against (a blind save could drop another
			// worker's token). Never fall back to an unlocked write - retry, then let
			// the caller decide what a persistent failure means (register raises,
			// unregister is safely retried on the next run).
			if (!$connection->lock(self::OPTION_INDEX, self::LOCK_TIMEOUT))
			{
				continue;
			}

			try
			{
				// A mutation that changes nothing is not written: the write costs a statement
				// and drops the cached index, and unregistering a token that is already gone -
				// or rotating a list that already stands in that order - is exactly what a
				// repeated pass does.
				$current = self::loadIndex();
				$mutated = $mutator($current);
				if ($mutated !== $current)
				{
					self::saveIndex($mutated);
				}
			}
			finally
			{
				$connection->unlock(self::OPTION_INDEX);
			}

			return true;
		}

		return false;
	}

	// --- legacy storage adoption -----------------------------------------

	/**
	 * Brings state left by an earlier version home: jobs from any former home of theirs
	 * ({@see LEGACY_OPTION_CATEGORIES}) into {@see OPTION_CATEGORY}, and the index from the
	 * job category into {@see OPTION_INDEX_CATEGORY}. Runs under the same named lock as every
	 * index mutation: an older-version enqueue still active during a rolling update
	 * serializes on it, so a token it registers cannot be lost here - and it is re-run every
	 * tick rather than once, because that older version keeps writing where it always did
	 * until the last frontend is updated.
	 *
	 * @return bool false when the lock was unavailable and legacy state may remain
	 */
	private static function adoptLegacyState(): bool
	{
		$jobSources = array_values(array_filter(
			self::LEGACY_OPTION_CATEGORIES,
			static fn(string $category): bool => Option::get($category, self::OPTION_INDEX, '') !== '',
		));
		$indexLeftWithTheJobs = Option::get(self::OPTION_CATEGORY, self::OPTION_INDEX, '') !== '';

		if ($jobSources === [] && !$indexLeftWithTheJobs)
		{
			return true;
		}

		$connection = Application::getConnection();
		if (!$connection->lock(self::OPTION_INDEX, self::LOCK_TIMEOUT))
		{
			return false;
		}

		try
		{
			foreach ($jobSources as $category)
			{
				self::adoptJobsFrom($category);
			}

			if ($indexLeftWithTheJobs)
			{
				self::adoptIndexFromJobCategory();
			}
		}
		finally
		{
			$connection->unlock(self::OPTION_INDEX);
		}

		return true;
	}

	/**
	 * The index as the previous version kept it - in the category of the job payloads.
	 * Merged rather than moved: an enqueue of the running version may have registered its own
	 * tokens in the new home already. The old record goes last, so an interrupted pass leaves
	 * every token reachable and is simply re-run.
	 */
	private static function adoptIndexFromJobCategory(): void
	{
		$legacyTokens = self::decodeTokens(Option::get(self::OPTION_CATEGORY, self::OPTION_INDEX, ''));

		$tokens = self::loadIndex();
		foreach ($legacyTokens as $token)
		{
			if (!in_array($token, $tokens, true))
			{
				$tokens[] = $token;
			}
		}
		self::saveIndex($tokens);

		Option::delete(self::OPTION_CATEGORY, ['name' => self::OPTION_INDEX]);
	}

	/**
	 * One former home, under the lock its caller holds. Values are copied first, the
	 * index is merged next and the legacy records are removed last - an interrupted pass
	 * keeps every job reachable and is simply re-run.
	 */
	private static function adoptJobsFrom(string $category): void
	{
		$legacyTokens = self::decodeTokens(Option::get($category, self::OPTION_INDEX, ''));

		foreach ($legacyTokens as $token)
		{
			$raw = Option::get($category, self::jobOptionName($token), '');
			// Never overwrite: a job copied by an earlier interrupted pass may have
			// progressed already, so the stale legacy payload loses.
			if ($raw !== '' && Option::get(self::OPTION_CATEGORY, self::jobOptionName($token), '') === '')
			{
				Option::set(self::OPTION_CATEGORY, self::jobOptionName($token), $raw);
			}
		}

		$tokens = self::loadIndex();
		foreach ($legacyTokens as $token)
		{
			if (!in_array($token, $tokens, true))
			{
				$tokens[] = $token;
			}
		}
		self::saveIndex($tokens);

		foreach ($legacyTokens as $token)
		{
			Option::delete($category, ['name' => self::jobOptionName($token)]);
		}
		Option::delete($category, ['name' => self::OPTION_INDEX]);
	}
}
