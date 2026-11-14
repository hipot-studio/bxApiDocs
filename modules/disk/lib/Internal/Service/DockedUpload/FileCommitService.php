<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\Analytics\DiskAnalytics;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\BizProcDocument;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Integration\BizProcManager;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Enum\DockedUpload\ConflictPolicy;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Disk\Internal\Model\DockedUploadItemTable;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadQueueRepository;
use Bitrix\Disk\Internal\Service\Logger\LoggerWrapper;
use Bitrix\Disk\Internals\ObjectNameService;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Storage;
use Bitrix\Disk\Version;
use Psr\Log\LoggerInterface;
use Bitrix\Main\Application;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\UI\FileUploader\TempFileTable;
use Bitrix\UI\FileUploader\Uploader;

/**
 * Idempotent, exactly-once fixation of an uploaded docked file (ALG-01).
 *
 * The public entry point {@see self::commit()} is meant to be called
 * synchronously from the phase-3 callers: from UpdateItemCommand on the
 * happy-path (item entering uploaded_to_temp with a temp token) and from
 * QueueRestoreProvider on the reconciliation path. It must NOT be wrapped in an
 * enclosing transaction by the caller.
 *
 * Transaction model (three durable units, never collapsed into one):
 *   1. TX1 (short): lock the item row (SELECT ... FOR UPDATE) and persist the
 *      stable intent token (if-null). COMMIT.
 *   2. domain write (Folder::addFile / File::addVersion) - AUTO-COMMIT: the disk
 *      object/version is durable immediately and its creation events are already
 *      dispatched synchronously inside the INSERT.
 *   3. TX2 (short): atomic guarded update of the item row -> committed +
 *      DISK_OBJECT_ID. This is THE durable commit point.
 * A crash between the units is covered by reconciliation keyed on the intent
 * token; the object-creation events are never re-dispatched, and the durable-only
 * side effects (BizProc, parity analytics) are emitted by this service after the
 * atomic update with an at-most-once (best-effort) guarantee, not exactly-once:
 * they never run twice, but a crash - or a throw swallowed here - in the window
 * between the atomic update and the emission loses them, since a repeated commit
 * exits early on the already-committed row. Known limitation, accepted in favour
 * of never starting a workflow twice; a durable outbox (at-least-once delivery)
 * is on the reliability backlog.
 */
class FileCommitService
{
	public const ERROR_QUEUE_MISSING = 'queue_missing';
	public const ERROR_PERMISSION = 'permission_fail';
	public const ERROR_OWNER_MISMATCH = 'owner_mismatch';
	public const ERROR_INVALID_TEMP_FILE = 'invalid_temp_file';
	public const ERROR_DUPLICATE_CONFLICT = 'duplicate_conflict';
	public const ERROR_INTERNAL = 'commit_failed';
	public const ERROR_RESTRICTION = DisplayStatusResolver::ERROR_RESTRICTION;
	public const ERROR_COMMON_STORAGE_BLOCKED = DisplayStatusResolver::ERROR_COMMON_STORAGE_BLOCKED;
	public const ERROR_BIZPROC_PARAMS_REQUIRED = DisplayStatusResolver::ERROR_BIZPROC_PARAMS_REQUIRED;
	public const ERROR_CONNECTION = DisplayStatusResolver::ERROR_CONNECTION;

	/**
	 * The client transport codes that mean "the link went, not the file": the code
	 * ui.uploader.core raises once its own chunk retries are spent, which the widget relays
	 * verbatim after its offline wait runs out (AC-074 escalation, upload-transport.ts).
	 *
	 * Its own fallback code (`transport_failed`, sent whenever the uploader named none) is
	 * deliberately NOT here: it stands for every failure with no code at all, so reading it
	 * as a network one would tell a user whose upload died of something else to check their
	 * connection.
	 */
	private const CLIENT_NETWORK_ERROR_CODES = ['NETWORK_ERROR'];

	/**
	 * Storage usage the PRE-CHECK has already paid for in this request, by storage id.
	 * Never read by the commit, which re-asks it live ({@see self::detectStopBeforeTransfer()}).
	 *
	 * @var array<int, int>
	 */
	private array $preCheckStorageUsage = [];

	/**
	 * Upper bound on the two memos below, mirroring {@see QueueTargetNameResolver::MEMO_LIMIT}:
	 * a run asks a handful of distinct questions, so a real one never comes near it, and the
	 * accelerator must not become an ever-growing map inside a service that outlives it.
	 */
	private const MEMO_LIMIT = 64;

	/**
	 * Required-parameter verdicts already taken in the current scope, keyed by everything the
	 * verdict depends on ({@see self::bizProcGateKey()}).
	 *
	 * @var array<string, bool>
	 */
	private array $bizProcGateVerdicts = [];

	/**
	 * Whether a storage has a file-create start trigger, by storage id - taken once per
	 * storage in the current scope ({@see self::runCreateTrigger()}).
	 *
	 * @var array<int, bool>
	 */
	private array $createTriggerVerdicts = [];

	/**
	 * The queue the two memos above were filled for, outside a declared run
	 * ({@see self::openBizProcGateScope()}).
	 */
	private ?int $bizProcGateQueueId = null;

	/**
	 * How many declared commit runs are open ({@see self::openCommitRun()}). While one is,
	 * the memos live for the run instead of for the queue.
	 */
	private int $commitRunDepth = 0;

	public function __construct(
		private readonly DockedUploadItemRepository $itemRepository,
		private readonly DockedUploadQueueRepository $queueRepository,
		private readonly FolderResolverService $folderResolver,
		private readonly CommitReconciler $reconciler,
		private readonly QuotaScenario $quotaScenario,
		private readonly PostCommitHookInterface $postCommitHook,
		private readonly Uploader $uploader,
		private readonly LoggerInterface $logger = new LoggerWrapper(null),
	)
	{
	}

	/**
	 * Fixes the file for the given item exactly once.
	 *
	 * @param array $bizProcParameters collected workflow parameters (API-03), routed
	 *   from updateItem to the durable-committed BizProc autostart (P2.T5). Defaults
	 *   to an empty set so the reconciliation/restore callers stay behavior-preserving.
	 * @param bool $bizProcParametersAreSigned whether $bizProcParameters carry a
	 *   signature the BizProc layer must verify (signed vs. legacy).
	 *
	 * @return int|null the disk object id of the committed file (new-file branch)
	 *   or of the file that received the new version (version branch); null when
	 *   the item could not be committed (permission, owner, quota, duplicate,
	 *   internal) - in which case the durable item/queue state carries the reason.
	 */
	public function commit(
		DockedUploadItem $item,
		array $bizProcParameters = [],
		bool $bizProcParametersAreSigned = false
	): ?int
	{
		$itemId = $item->getId();
		if (!is_int($itemId))
		{
			return null;
		}

		$this->openBizProcGateScope((int)$item->getQueueId());

		// --- TX1: lock the row, short-circuit if already committed, pin token ---
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$row = $this->lockItemRow($itemId);
			if ($row === null)
			{
				$connection->commitTransaction();

				return null;
			}

			if ($row['STATUS'] === ItemStatus::Committed->value && (int)$row['DISK_OBJECT_ID'] > 0)
			{
				$connection->commitTransaction();

				return (int)$row['DISK_OBJECT_ID'];
			}

			// Symmetric to the committed short-circuit: the cancel is already durable under
			// our own lock, so {@see self::atomicMarkCommitted()} below is bound to take 0
			// rows and everything in between - consuming the one-shot temp, creating the
			// object and dispatching its pull event - is work certain to be compensated
			// away. NOT a fix for the race: a cancel landing after this lock stays covered
			// by the guarded update and by DurableOrphanReleaser, as before.
			if ($row['STATUS'] === ItemStatus::Canceled->value)
			{
				$connection->commitTransaction();

				return null;
			}

			$token = (string)$row['INTENT_TOKEN'];
			if ($token === '')
			{
				$token = $this->generateIntentToken();
				DockedUploadItemTable::update($itemId, [
					'INTENT_TOKEN' => $token,
					'UPDATE_TIME' => new DateTime(),
				]);
			}
			$item->setIntentToken($token);

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}

		$queue = $this->queueRepository->getById((int)$item->getQueueId());
		if ($queue === null)
		{
			$this->failItem($item, self::ERROR_QUEUE_MISSING);

			return null;
		}
		$userId = (int)$queue->getUserId();

		$isVersionBranch = ($item->getTargetFileId() !== null && $item->getTargetFileId() > 0);

		// --- Reconciliation probe: the domain write may already exist (crash) ---
		$reconciledId = $this->tryReconcile($item, $token, $isVersionBranch, $userId, $bizProcParameters, $bizProcParametersAreSigned);
		if ($reconciledId !== null)
		{
			return $reconciledId;
		}

		return $isVersionBranch
			? $this->commitNewVersion($item, $token, $userId, $bizProcParameters, $bizProcParametersAreSigned)
			: $this->commitNewFile($item, $token, $userId, $bizProcParameters, $bizProcParametersAreSigned)
		;
	}

	/**
	 * If an object/version already carries our token, the domain write happened
	 * before a crash: flip the row to committed exactly once and emit the durable
	 * side effects. Returns the disk object id, or null when nothing to reconcile.
	 */
	private function tryReconcile(
		DockedUploadItem $item,
		string $token,
		bool $isVersionBranch,
		int $userId,
		array $bizProcParameters,
		bool $bizProcParametersAreSigned
	): ?int
	{
		if ($isVersionBranch)
		{
			$version = $this->reconciler->findVersionByIntentToken($token);
			if ($version === null)
			{
				return null;
			}

			$fileObjectId = (int)$item->getTargetFileId();
			$file = File::loadById($fileObjectId, ['STORAGE']);
			if ($file === null)
			{
				return null;
			}

			$this->warnAboutSkippedRequiredBizProc($item, $token, $file->getStorage(), false, $bizProcParameters, $bizProcParametersAreSigned);
			$this->finalizeCommit($item, $fileObjectId, $file, $file->getStorage(), false, $userId, $bizProcParameters, $bizProcParametersAreSigned);

			return $fileObjectId;
		}

		$file = $this->reconciler->findFileByIntentToken($token);
		if ($file === null)
		{
			return null;
		}

		$this->warnAboutSkippedRequiredBizProc($item, $token, $file->getStorage(), true, $bizProcParameters, $bizProcParametersAreSigned);
		$this->finalizeCommit($item, (int)$file->getId(), $file, $file->getStorage(), true, $userId, $bizProcParameters, $bizProcParametersAreSigned);

		return (int)$file->getId();
	}

	/**
	 * Reconciliation finalizes BEFORE the required-parameter gate and its callers
	 * (restore, cleanup agent) carry no collected parameters, so a required-parameter
	 * autostart workflow silently does not start here (accepted v1 at-most-once
	 * limitation). Observability only: surface the skip, never change the outcome.
	 */
	private function warnAboutSkippedRequiredBizProc(
		DockedUploadItem $item,
		string $token,
		?Storage $storage,
		bool $isNewFile,
		array $bizProcParameters,
		bool $bizProcParametersAreSigned
	): void
	{
		if ($bizProcParameters !== [] || $storage === null || !$this->isBizProcStartable($storage))
		{
			return;
		}

		$autoExecuteType = $isNewFile ? \CBPDocumentEventType::Create : \CBPDocumentEventType::Edit;
		if (!$this->askBizProcGate((int)$storage->getId(), $autoExecuteType, $bizProcParameters, $bizProcParametersAreSigned))
		{
			return;
		}

		$this->logger->warning('docked reconcile skips required bizproc', [
			'itemId' => (int)$item->getId(),
			'queueId' => (int)$item->getQueueId(),
			'storageId' => (int)$storage->getId(),
			'intentToken' => $token,
		]);
	}

	private function commitNewFile(
		DockedUploadItem $item,
		string $token,
		int $userId,
		array $bizProcParameters,
		bool $bizProcParametersAreSigned
	): ?int
	{
		// Defense-in-depth for the create-time target invariant (API-01: a file element
		// without a valid target is rejected). A non-positive targetFolderId would make
		// resolveTargetFolder() silently fall back to the user's default upload folder,
		// so a new-file without a valid destination must fail here rather than land
		// somewhere the client never asked for. ERROR_PERMISSION is non-retriable - a
		// retry cannot conjure a target.
		if ((int)$item->getTargetFolderId() <= 0)
		{
			return $this->failItem($item, self::ERROR_PERMISSION);
		}

		$folder = $this->folderResolver->resolveTargetFolder((int)$item->getTargetFolderId(), $userId);
		if ($folder === null)
		{
			return $this->failItem($item, self::ERROR_PERMISSION);
		}

		$storage = $folder->getStorage();
		$blockCode = $this->classifyCommitAccessBlock(
			$storage,
			$userId,
			fn(Storage $liveStorage): bool => $this->folderResolver->canAddToFolder($folder, $liveStorage, $userId),
		);
		if ($blockCode !== null)
		{
			return $this->failItem($item, $blockCode);
		}

		// B4 / AC-049/AC-051: a required-parameter autostart workflow must never be
		// silently skipped after the irreversible commit. When BizProc would run for
		// this storage, enforce that the collected parameters satisfy every active
		// parameterized autostart template BEFORE any durable write; an unmet set fails
		// the item non-retriably (a retry cannot conjure the missing input).
		if (
			$this->isBizProcStartable($storage)
			&& $this->askBizProcGate(
				(int)$storage->getId(),
				\CBPDocumentEventType::Create,
				$bizProcParameters,
				$bizProcParametersAreSigned
			)
		)
		{
			return $this->failItem($item, self::ERROR_BIZPROC_PARAMS_REQUIRED);
		}

		// R7 (ALG-01-safe): run the fast quota pre-check BEFORE materialising the
		// persistent b_file when the declared size is known, so a quota stop never
		// leaves an orphaned persistent file behind.
		$declaredSize = (int)($item->getSize() ?? 0);
		if ($declaredSize > 0)
		{
			$quotaStop = $this->quotaScenario->detectForUpload($storage, $declaredSize, $userId);
			if ($quotaStop !== null)
			{
				return $this->applyQuotaStop($item, $quotaStop);
			}
		}

		// Single-file replace (no folder policy): detect a live (NAME, PARENT_ID) collision
		// BEFORE consuming the temp. materialize() makes the temp persistent and drops its
		// loader row, so reaching the DuplicateEntryException path below would strand the
		// temp token; the manual Replace would then re-commit a version off a dead token and
		// fail retriably. Marking the conflict here keeps the temp (and its bytes) alive so
		// Replace re-commits as a new version on the first click. A folder-policy child skips
		// this: skip/replace_all resolve with the persistent b_file already in hand via the
		// exception path, which also stays as the backstop for a same-name commit race.
		if ($this->resolveParentConflictPolicy($item) === null
			&& $this->findCollidingFileId((string)$item->getName(), (int)$item->getTargetFolderId()) !== null)
		{
			return $this->markDuplicateConflict($item);
		}

		$bFileId = $this->materialize($item, $userId);
		if ($bFileId === null)
		{
			return null;
		}

		// R13 (security): the declared HTTP size is only a fast pre-check hint - the
		// SIZE persisted on the object and the authoritative quota decision are taken
		// from the materialised CFile FILE_SIZE, never from the client-supplied size,
		// so a spoofed small declaredSize cannot understate SIZE or dodge the storage
		// quota (mechanism 2).
		$size = $this->resolveActualSize($bFileId);
		$sizeError = self::classifyActualSize($size);
		if ($sizeError !== null)
		{
			$this->deleteOrphanBFile($bFileId);

			return $this->failItem($item, $sizeError);
		}

		// R13: mandatory quota re-check against the ACTUAL size - even when the
		// declared pre-check passed, the real file may be larger. Releases the
		// just-materialised b_file on a stop so no orphan persistent file is left.
		$quotaStop = $this->quotaScenario->detectForUpload($storage, $size, $userId);
		if ($quotaStop !== null)
		{
			return $this->applyQuotaStop($item, $quotaStop, $bFileId);
		}

		try
		{
			$file = $folder->addFile(
				[
					'NAME' => $item->getName(),
					'FILE_ID' => $bFileId,
					'SIZE' => $size,
					'CREATED_BY' => $userId,
					'UPLOAD_INTENT_TOKEN' => $token,
					'DISABLE_AUTO_BIZPROC' => true,
				],
				[],
				false
			);
		}
		catch (DuplicateEntryException)
		{
			return $this->onDuplicateViolation($item, $token, false, $userId, $bFileId, $bizProcParameters, $bizProcParametersAreSigned);
		}

		if ($file === null)
		{
			if ($folder->getErrorByCode(File::ERROR_SIZE_RESTRICTION) !== null)
			{
				return $this->applyQuotaStop($item, $this->quotaScenario->boxStop(), $bFileId);
			}

			// A name collision is caught by ObjectNameService (soft error) before it
			// ever reaches the DB unique index, so route it through the same
			// token-vs-(NAME,PARENT_ID) discrimination as a DB unique-violation.
			if ($folder->getErrorByCode(BaseObject::ERROR_NON_UNIQUE_NAME) !== null)
			{
				return $this->onDuplicateViolation($item, $token, false, $userId, $bFileId, $bizProcParameters, $bizProcParametersAreSigned);
			}

			$this->deleteOrphanBFile($bFileId);

			return $this->failItem($item, self::ERROR_INTERNAL);
		}

		$this->postCommitHook->beforeAtomicUpdate($item);
		if ($this->finalizeCommit($item, (int)$file->getId(), $file, $storage, true, $userId, $bizProcParameters, $bizProcParametersAreSigned))
		{
			return (int)$file->getId();
		}

		// R4: the guarded update lost the row - either a concurrent reconcile bound
		// THIS same object (durable-committed, keep it) or a concurrent cancel won
		// the row (nothing points at the object we just created -> delete it as an
		// orphan so no committed disk object is left dangling off the queue).
		if ($this->objectStayedBound($item, (int)$file->getId()))
		{
			return (int)$file->getId();
		}

		$this->deleteOrphanObject($file, $userId);

		return null;
	}

	private function commitNewVersion(
		DockedUploadItem $item,
		string $token,
		int $userId,
		array $bizProcParameters,
		bool $bizProcParametersAreSigned
	): ?int
	{
		$fileObjectId = (int)$item->getTargetFileId();
		$file = File::loadById($fileObjectId, ['STORAGE']);
		if ($file === null)
		{
			return $this->failItem($item, self::ERROR_PERMISSION);
		}

		$storage = $file->getStorage();
		$blockCode = $this->classifyCommitAccessBlock(
			$storage,
			$userId,
			fn(Storage $liveStorage): bool => $this->folderResolver->canUpdateFile($file, $liveStorage, $userId),
		);
		if ($blockCode !== null)
		{
			return $this->failItem($item, $blockCode);
		}

		// B4 / AC-049/AC-051: a required-parameter autostart workflow must never be
		// silently skipped after the irreversible commit. When BizProc would run for
		// this storage, enforce that the collected parameters satisfy every active
		// parameterized autostart template BEFORE any durable write; an unmet set fails
		// the item non-retriably (a retry cannot conjure the missing input).
		if (
			$this->isBizProcStartable($storage)
			&& $this->askBizProcGate(
				(int)$storage->getId(),
				\CBPDocumentEventType::Edit,
				$bizProcParameters,
				$bizProcParametersAreSigned
			)
		)
		{
			return $this->failItem($item, self::ERROR_BIZPROC_PARAMS_REQUIRED);
		}

		// R7: fast quota pre-check BEFORE materialisation when the size is known.
		$declaredSize = (int)($item->getSize() ?? 0);
		if ($declaredSize > 0)
		{
			$quotaStop = $this->quotaScenario->detectForUpload($storage, $declaredSize, $userId);
			if ($quotaStop !== null)
			{
				return $this->applyQuotaStop($item, $quotaStop);
			}
		}

		$bFileId = $this->materialize($item, $userId);
		if ($bFileId === null)
		{
			return null;
		}

		// R13: mandatory quota re-check against the AUTHORITATIVE materialised size,
		// symmetric with the new-file branch - the declared pre-check above is only a
		// fast gate and a spoofed small size must not bypass the storage quota. The
		// version SIZE itself is taken from the CFile by addVersion, so only the quota
		// decision needs the actual size here.
		$actualSize = $this->resolveActualSize($bFileId);
		$sizeError = self::classifyActualSize($actualSize);
		if ($sizeError !== null)
		{
			$this->deleteOrphanBFile($bFileId);

			return $this->failItem($item, $sizeError);
		}

		$quotaStop = $this->quotaScenario->detectForUpload($storage, $actualSize, $userId);
		if ($quotaStop !== null)
		{
			return $this->applyQuotaStop($item, $quotaStop, $bFileId);
		}

		return $this->addVersionAndFinalize(
			$item, $file, $storage, $bFileId, $token, $userId, $bizProcParameters, $bizProcParametersAreSigned
		);
	}

	/**
	 * Adds a new version from an already-materialised b_file and drives the durable
	 * commit. Shared by the plain version branch and the folder replace_all
	 * auto-resolution (R2), which both hold the persistent b_file in hand and must
	 * NOT re-materialise the temp (makePersistent already consumed the temp row).
	 */
	private function addVersionAndFinalize(
		DockedUploadItem $item,
		File $file,
		?Storage $storage,
		int $bFileId,
		string $token,
		int $userId,
		array $bizProcParameters,
		bool $bizProcParametersAreSigned
	): ?int
	{
		$fileArray = \CFile::getFileArray($bFileId);
		if (!$fileArray)
		{
			$this->deleteOrphanBFile($bFileId);

			return $this->failItem($item, self::ERROR_INTERNAL);
		}

		try
		{
			$version = $file->addVersion(
				$fileArray,
				$userId,
				true,
				[
					'UPLOAD_INTENT_TOKEN' => $token,
					'disableAutoBizProc' => true,
				]
			);
		}
		catch (DuplicateEntryException)
		{
			return $this->onDuplicateViolation($item, $token, true, $userId, $bFileId, $bizProcParameters, $bizProcParametersAreSigned);
		}

		if ($version === null)
		{
			if ($file->getErrorByCode(File::ERROR_SIZE_RESTRICTION) !== null)
			{
				return $this->applyQuotaStop($item, $this->quotaScenario->boxStop(), $bFileId);
			}

			// Version::add() failed after updateContent() already published the new b_file
			// on the object head. No content is lost: deleteOrphanBFile() refuses to drop a
			// b_file a live object references (B1). Accepted residual (B1a): on this rare
			// version-row write failure the head carries the new content without a version
			// row while the item is failed; not rolled back, since updateContent() also
			// mutates links/recently-used, so a faithful rollback is more fragile than the gap.
			$this->deleteOrphanBFile($bFileId);

			return $this->failItem($item, self::ERROR_INTERNAL);
		}

		$this->postCommitHook->beforeAtomicUpdate($item);
		if ($this->finalizeCommit($item, (int)$file->getId(), $file, $storage, false, $userId, $bizProcParameters, $bizProcParametersAreSigned))
		{
			return (int)$file->getId();
		}

		// R4: lost the guarded row. If a concurrent reconcile bound this same file
		// (durable-committed), keep it; if a concurrent cancel won, the version we
		// just appended is an orphan - drop it (Version::delete rolls the file head
		// back to the previous version), never touching the pre-existing file.
		if ($this->objectStayedBound($item, (int)$file->getId()))
		{
			return (int)$file->getId();
		}

		$this->deleteOrphanVersion($version, $userId);

		return null;
	}

	/**
	 * Discriminates a unique-violation without parsing the driver message: a
	 * re-read by intent token tells token-UNIQUE (reconcile) from a
	 * (NAME, PARENT_ID) collision.
	 *
	 * On a real name collision the folder-level conflict policy (P3.T5/AC-071-072)
	 * decides: a folder-item child is auto-skipped (`skip`) or auto-replaced as a new
	 * version (`replace_all`); a single file outside a folder (no policy) keeps the
	 * manual duplicate_conflict path.
	 */
	private function onDuplicateViolation(
		DockedUploadItem $item,
		string $token,
		bool $isVersionBranch,
		int $userId,
		?int $bFileId,
		array $bizProcParameters,
		bool $bizProcParametersAreSigned
	): ?int
	{
		$winner = $isVersionBranch
			? $this->reconciler->findVersionByIntentToken($token)
			: $this->reconciler->findFileByIntentToken($token)
		;

		if ($winner !== null)
		{
			// makePersistent() reuses the temp b_file instead of cloning it, so a
			// concurrent attempt at the same item holds the VERY b_file the winner has
			// just bound to its durable object/version. Release ours only when the
			// winner took a different one - otherwise this would delete live content.
			if ($bFileId !== null && (int)$winner->getFileId() !== $bFileId)
			{
				$this->deleteOrphanBFile($bFileId);
			}

			return $this->commit($item, $bizProcParameters, $bizProcParametersAreSigned);
		}

		// The version branch has no (NAME, PARENT_ID) uniqueness to auto-resolve -
		// a non-reconcilable duplicate there is an integrity error, not a name clash.
		if (!$isVersionBranch)
		{
			$policy = $this->resolveParentConflictPolicy($item);
			if ($policy === ConflictPolicy::Skip)
			{
				return $this->autoSkip($item, $bFileId);
			}
			if ($policy === ConflictPolicy::ReplaceAll)
			{
				return $this->applyReplaceAll(
					$item, $token, $userId, (int)$bFileId, $bizProcParameters, $bizProcParametersAreSigned
				);
			}
		}

		$this->deleteOrphanBFile($bFileId);

		return $this->markDuplicateConflict($item);
	}

	private function markDuplicateConflict(DockedUploadItem $item): ?int
	{
		// R4: guard the transition so a duplicate branch racing a concurrent cancel
		// (or a durable commit) can never resurrect the row as duplicate_conflict.
		if (!$this->atomicMarkTerminalFailure((int)$item->getId(), ItemStatus::DuplicateConflict, self::ERROR_DUPLICATE_CONFLICT))
		{
			return null;
		}

		$item->setStatus(ItemStatus::DuplicateConflict)->setErrorCode(self::ERROR_DUPLICATE_CONFLICT);

		return null;
	}

	/**
	 * Resolves the folder-level conflict policy from the item's parent folder-item.
	 * A folder-item always carries a policy (default skip); a top-level file has no
	 * parent and therefore no policy.
	 */
	private function resolveParentConflictPolicy(DockedUploadItem $item): ?ConflictPolicy
	{
		$parentItemId = $item->getParentItemId();
		if ($parentItemId === null || $parentItemId <= 0)
		{
			return null;
		}

		$parent = $this->itemRepository->getById($parentItemId);
		if ($parent === null || !$parent->isFolder())
		{
			return null;
		}

		return $parent->getConflictPolicy() ?? ConflictPolicy::default();
	}

	/**
	 * `skip` policy: auto-skip the colliding element (no manual resolveConflict),
	 * freeing its transport temp and the orphaned persistent b_file.
	 *
	 * The temp and the orphaned b_file are released BEFORE the guard: a materialised
	 * b_file nobody bound is never adopted by the cleanup agent, so leaving it behind a
	 * lost guard would leak it forever, while {@see self::deleteOrphanBFile()} already
	 * refuses to touch a b_file a durable object points at.
	 */
	private function autoSkip(DockedUploadItem $item, ?int $bFileId): ?int
	{
		$this->releaseTemp($item);
		$this->deleteOrphanBFile($bFileId);

		// R4: the skip is a terminal transition like every other one in this class - guard
		// it, so a row a concurrent cancel (or a durable commit) has already parked in a
		// terminal state is never rewritten from this pre-materialise snapshot.
		if (!$this->atomicMarkTerminalFailure((int)$item->getId(), ItemStatus::Canceled, null))
		{
			return null;
		}

		$item->setStatus(ItemStatus::Canceled)->setErrorCode(null);

		$this->logger->info('docked duplicate auto-skip', [
			'itemId' => (int)$item->getId(),
			'queueId' => (int)$item->getQueueId(),
		]);

		// An auto-skip is a clean terminal too: it may well be the last leaf the queue
		// was waiting for.
		$this->completeQueueIfDrained($item);

		return null;
	}

	/**
	 * `replace_all` policy: auto-replace by writing the in-hand b_file as a new
	 * version of the colliding file (same version branch the manual replace uses),
	 * without re-materialising the temp. Binds TARGET_FILE_ID durably first so a
	 * crash reconciles as a version.
	 */
	private function applyReplaceAll(
		DockedUploadItem $item,
		string $token,
		int $userId,
		int $bFileId,
		array $bizProcParameters,
		bool $bizProcParametersAreSigned
	): ?int
	{
		$targetFileId = $this->findCollidingFileId((string)$item->getName(), (int)$item->getTargetFolderId());
		if ($targetFileId === null)
		{
			// The colliding file vanished between the failed add and now (rare race):
			// keep the manual conflict path rather than guess.
			return $this->markDuplicateConflict($item);
		}

		$file = File::loadById($targetFileId, ['STORAGE']);
		if ($file === null)
		{
			return $this->markDuplicateConflict($item);
		}

		$storage = $file->getStorage();
		$blockCode = $this->classifyCommitAccessBlock(
			$storage,
			$userId,
			fn(Storage $liveStorage): bool => $this->folderResolver->canUpdateFile($file, $liveStorage, $userId),
		);
		if ($blockCode !== null)
		{
			$this->deleteOrphanBFile($bFileId);

			return $this->failItem($item, $blockCode);
		}

		// R4: bind TARGET_FILE_ID durably with a guarded transition instead of a
		// full-row save, so a whole-queue cancel landing in the window between the
		// commit lock release and this write is not clobbered back to uploaded_to_temp
		// (and then carried to committed by the atomic update). On a lost CAS the item
		// already reached a terminal state - abort the commit path without side effects,
		// releasing the in-hand orphan b_file.
		if (!$this->itemRepository->bindReplaceTarget((int)$item->getId(), $userId, $targetFileId))
		{
			$this->deleteOrphanBFile($bFileId);

			return null;
		}

		$item->setTargetFileId($targetFileId)->setStatus(ItemStatus::UploadedToTemp)->setErrorCode(null);

		return $this->addVersionAndFinalize(
			$item, $file, $storage, $bFileId, $token, $userId, $bizProcParameters, $bizProcParametersAreSigned
		);
	}

	private function findCollidingFileId(string $name, int $parentFolderId): ?int
	{
		if ($name === '' || $parentFolderId <= 0)
		{
			return null;
		}

		// Resolve the colliding object with the same service Disk uses to enforce
		// (NAME, PARENT_ID) uniqueness on add (R10), so a normalized (case/collation)
		// collision resolves to the file Disk actually stored instead of degrading to
		// a manual duplicate_conflict on an exact-name miss.
		$nameService = new ObjectNameService($name, $parentFolderId, ObjectTable::TYPE_FILE);
		$uniqueResult = $nameService->isUniqueName(returnOpponentId: true);
		if ($uniqueResult->isSuccess())
		{
			return null;
		}

		$opponentId = (int)($uniqueResult->getData()['opponentId'] ?? 0);
		if ($opponentId <= 0)
		{
			return null;
		}

		// ObjectNameService ignores TYPE / DELETED_TYPE - bind a replace only to a
		// live file, never to a folder or a trashed object under the same parent.
		$row = ObjectTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=ID' => $opponentId,
				'=TYPE' => ObjectTable::TYPE_FILE,
				'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
			],
			'limit' => 1,
		])->fetch();

		return $row ? (int)$row['ID'] : null;
	}

	private function releaseTemp(DockedUploadItem $item): void
	{
		$tempToken = (string)$item->getTempToken();
		if ($tempToken === '')
		{
			return;
		}

		try
		{
			// remove() reports a per-token outcome instead of throwing. Freeing stays
			// best-effort here (the TTL agent is the backstop), but it stops being
			// silent: a temp outliving its item is a leak worth seeing in the log.
			foreach ($this->uploader->remove([$tempToken]) as $result)
			{
				if ($result->isSuccess())
				{
					continue;
				}

				$this->logger->warning('docked temp release failed', [
					'itemId' => (int)$item->getId(),
					'queueId' => (int)$item->getQueueId(),
					'errorCodes' => array_map(
						static fn($error) => (string)$error->getCode(),
						$result->getErrors(),
					),
				]);
			}
		}
		catch (\Throwable)
		{
			// best-effort; the TTL agent is the backstop.
		}
	}

	/**
	 * Releases a b_file this attempt materialised but never glued to a durable
	 * artifact. Refuses to touch a b_file some object still points at: makePersistent()
	 * reuses the temp b_file rather than cloning it, so an attempt that lost a race can
	 * hold the very b_file the winner already bound - deleting it there would destroy
	 * live content instead of freeing a leak.
	 */
	private function deleteOrphanBFile(?int $bFileId): void
	{
		if ($bFileId === null || $bFileId <= 0 || $this->isBFileReferenced($bFileId))
		{
			return;
		}

		try
		{
			\CFile::Delete($bFileId);
		}
		catch (\Throwable)
		{
			// best-effort; an unreferenced b_file left behind is only a resource leak.
		}
	}

	/**
	 * Whether any disk object still points at the b_file.
	 *
	 * Covers the version branch as well: addVersion() publishes the new b_file on the
	 * object (updateContent) before writing the version row, so version content is
	 * never seen as unreferenced here. Trashed objects count as references too - their
	 * content is restorable. Indexed by IX_DISK_O_11 (FILE_ID).
	 */
	private function isBFileReferenced(int $bFileId): bool
	{
		$row = ObjectTable::getList([
			'select' => ['ID'],
			'filter' => ['=FILE_ID' => $bFileId],
			'limit' => 1,
		])->fetch();

		return (bool)$row;
	}

	/**
	 * The durable commit point + one-shot durable side effects.
	 *
	 * The guarded UPDATE (STATUS NOT IN (committed, canceled)) makes the transition -
	 * and hence the side effects - happen at most once even under a concurrent
	 * reconcile, and refuses to overwrite a concurrent cancel (R4).
	 *
	 * @return bool true when THIS call flipped the row to committed; false when the
	 *   guarded update matched no row (already committed by a concurrent reconcile,
	 *   or canceled meanwhile). A side effect that threw does not turn the durable
	 *   commit into a failure - see the at-most-once note on the class.
	 */
	private function finalizeCommit(
		DockedUploadItem $item,
		int $diskObjectId,
		File $file,
		?Storage $storage,
		bool $isNewFile,
		int $userId,
		array $bizProcParameters = [],
		bool $bizProcParametersAreSigned = false
	): bool
	{
		if (!$this->atomicMarkCommitted((int)$item->getId(), $diskObjectId))
		{
			return false;
		}

		$item->setStatus(ItemStatus::Committed)->setDiskObjectId($diskObjectId)->setErrorCode(null);

		// Right behind the durable transition, ahead of the side effects: the two writes are
		// not one transaction, so whatever runs between them is a window in which the process
		// can die leaving a finished queue stored active. The side effects read no queue
		// state, so closing it first costs nothing and narrows that window from the duration
		// of a workflow autostart to two adjacent statements. The restore reconciles what is
		// left ({@see \Bitrix\Disk\Public\Provider\DockedUpload\QueueRestoreProvider}).
		$this->completeQueueIfDrained($item);

		try
		{
			$this->runPostCommitSideEffects(
				$diskObjectId, $storage, $isNewFile, $userId, $bizProcParameters, $bizProcParametersAreSigned
			);
		}
		catch (\Throwable $exception)
		{
			// The row is already durable-committed: a failing side effect must not take
			// the queue completion down with it, nor report the committed item as failed.
			$this->logger->warning('docked post-commit side effects failed', [
				'itemId' => (int)$item->getId(),
				'queueId' => (int)$item->getQueueId(),
				'diskObjectId' => $diskObjectId,
				'message' => $exception->getMessage(),
			]);
		}

		return true;
	}

	/**
	 * AC-077: a queue whose file items all reached a clean terminal state has nothing
	 * left to do - end its lifecycle so a page reload stops restoring it. Attempted
	 * from the paths that produce such a terminal leaf (the durable commit and the
	 * conflict auto-skip); the transition itself is guarded and re-derives the drain
	 * from the item rows, so a concurrent commit finishing the sibling leaf cannot
	 * complete the queue twice.
	 */
	private function completeQueueIfDrained(DockedUploadItem $item): void
	{
		$this->queueRepository->markCompletedIfDrained((int)$item->getQueueId());
	}

	/**
	 * Tells apart the two reasons the guarded update matched no row after we created
	 * a fresh object: a concurrent reconcile that bound THIS very object (return
	 * true - the object is durable-committed and must be kept) vs. a concurrent
	 * cancel (return false - the object we created is now an orphan and the caller
	 * must delete it). A committed row pointing at a different object id is treated
	 * as "not bound" so the just-created orphan is still cleaned up.
	 */
	private function objectStayedBound(DockedUploadItem $item, int $diskObjectId): bool
	{
		$table = DockedUploadItemTable::getTableName();
		$itemId = (int)$item->getId();
		$sql = "SELECT STATUS, DISK_OBJECT_ID FROM {$table} WHERE ID = {$itemId}";

		$row = Application::getConnection()->query($sql)->fetch();

		return $row
			&& $row['STATUS'] === ItemStatus::Committed->value
			&& (int)$row['DISK_OBJECT_ID'] === $diskObjectId
		;
	}

	/**
	 * Hard-deletes a just-created new-file disk object that lost the durable row to a
	 * concurrent cancel. Reuses the domain delete (object + b_file + version).
	 * Best-effort: a surviving orphan is a resource leak, not corruption.
	 */
	private function deleteOrphanObject(File $file, int $userId): void
	{
		try
		{
			$file->delete($userId);
		}
		catch (\Throwable)
		{
			// best-effort; the orphaned object is a leak, not corruption.
		}
	}

	/**
	 * Drops a just-appended version that lost the durable row to a concurrent cancel.
	 * Version::delete rolls the file head back to the previous version and frees the
	 * version b_file; the pre-existing file object is never removed.
	 */
	private function deleteOrphanVersion(?Version $version, int $userId): void
	{
		if ($version === null)
		{
			return;
		}

		try
		{
			$version->delete($userId);
		}
		catch (\Throwable)
		{
			// best-effort; the orphaned version is a leak, not corruption.
		}
	}

	/**
	 * BizProc autostart + parity analytics, triggered strictly by the durable
	 * committed state (after the atomic update), never by the mere domain write.
	 *
	 * The collected workflow parameters (API-03) are delivered here from updateItem
	 * (P2.T5), so a workflow with required parameters starts with them; signed and
	 * legacy (unsigned) parameter sets are both supported.
	 *
	 * The "active version" of a parameter set is, for the server, nothing but the
	 * values that arrived with the fixing call of THIS element - the same call that
	 * carries the uploaded_to_temp transition. Versions are the client's bookkeeping:
	 * the server neither stores, numbers nor compares them, and a version number or
	 * set timestamp must not be introduced here - either would immediately demand the
	 * server-side parameter storage the ADR rules out (P8.T2). AC-078 therefore holds
	 * by construction: a file fixed after a new version was saved arrives with the new
	 * values because the client put them there, one fixed before arrives with the old
	 * ones, and nothing is applied retroactively or re-run (AC-049). The unit of the
	 * guarantee is the QUEUE ITEM, not the Disk file: two uploads of two versions of
	 * one file are two items and two legitimate starts.
	 *
	 * Accepted debt: THREE paths commit with no client behind them, and they do not share
	 * one outcome. The two reconciling ones (restore, cleanup agent) finalize an artifact
	 * that is already durable, ahead of the required-parameter gate, so a parameterized
	 * required workflow does not start for them at all - the skip is surfaced by
	 * {@see self::warnAboutSkippedRequiredBizProc()} instead of passing silently. The queue
	 * restore ({@see \Bitrix\Disk\Public\Command\DockedUpload\ResumeCommand}) is the third,
	 * and it lands elsewhere: it carries no set either - API-07 takes a queue id and nothing
	 * else, the sets living on the client - but it runs an ORDINARY commit, so its elements
	 * meet the hard gate and end in ERROR_BIZPROC_PARAMS_REQUIRED instead of being fixed.
	 * That outcome is chosen rather than inherited: the code is retriable, the failure keeps
	 * the temp token ({@see self::failItem()}), and the widget collects the set per
	 * (storage, scenario) pair and requeues those rows, which are then re-committed from the
	 * bytes still sitting in temp - nothing crosses the wire twice. Skipping such an element
	 * instead would strand it: a row left in uploaded_to_temp is carried on by no one, on
	 * either side of the wire.
	 *
	 * The workflow initiator is the QUEUE OWNER, passed explicitly: the agent/cron
	 * reconcile path has no authorized user (CurrentUser = 0, which the bizproc
	 * Starter ignores), so relying on the ambient user would start the workflow
	 * anonymously there.
	 */
	private function runPostCommitSideEffects(
		int $fileObjectId,
		?Storage $storage,
		bool $isNewFile,
		int $userId,
		array $bizProcParameters = [],
		bool $bizProcParametersAreSigned = false
	): void
	{
		if ($storage !== null && $this->isBizProcStartable($storage))
		{
			$this->startAutoBizProc(
				(int)$storage->getId(), $fileObjectId, $isNewFile, $userId, $bizProcParameters, $bizProcParametersAreSigned
			);
		}

		DiskAnalytics::sendUploadFileDirectlyToDiskByIdEvent($fileObjectId);
	}

	protected function isBizProcStartable(Storage $storage): bool
	{
		return Loader::includeModule('bizproc')
			&& BizProcManager::isAvailable()
			&& $storage->isEnabledBizProc()
		;
	}

	/**
	 * The autostart part of the after-create/after-edit effects, raised for BOTH halves of
	 * the storage's templates.
	 *
	 * BizProcDocument partitions the active autostart templates of a (storage, event) pair by
	 * the parameter set it is handed: a non-empty set selects the templates that declare
	 * parameters, an empty one selects those that declare none. One call therefore reaches
	 * exactly one half, and the ordinary upload gets the union only because two different
	 * callers raise it - File::add() raises the domain after-create effects with no set at
	 * all, and disk.file.upload adds the collected one on top. Here the domain half is
	 * suppressed (DISABLE_AUTO_BIZPROC) to keep the effects behind the durable commit point,
	 * so this is the only caller left and it has to raise both halves itself; otherwise a
	 * storage holding a parameterless template next to a parameterized one would never start
	 * the parameterless one through a docked upload.
	 *
	 * No template can be started twice by the two calls: the halves are complementary by
	 * construction (a template either declares parameters or it does not), and with an empty
	 * collected set the single call already IS the parameterless half, so no second one is
	 * emitted at all - AC-049 holds for every set, empty, partial or full.
	 *
	 * The order is the legacy one as well: the domain write raises the parameterless half
	 * first and disk.file.upload adds the collected one on top of it, so a workflow that reads
	 * what an earlier one wrote sees the same picture here. The start trigger belongs to the
	 * create event as a whole rather than to a half, so it stays outside and keeps firing
	 * exactly once, ahead of both - the order BizProcDocument::runAfterCreate() has.
	 */
	protected function startAutoBizProc(
		int $storageId,
		int $fileObjectId,
		bool $isNewFile,
		int $userId,
		array $parameters,
		bool $parametersAreSigned
	): void
	{
		if ($isNewFile)
		{
			$this->runCreateTrigger($storageId, $fileObjectId, $userId);
		}

		if ($parameters !== [])
		{
			// The parameterless half carries no values, so it runs exactly as the domain-level
			// runAfterCreate()/runAfterEdit() would: an empty, unsigned set.
			$this->startAutoBizProcHalf($storageId, $fileObjectId, $isNewFile, $userId, [], false);
		}

		$this->startAutoBizProcHalf($storageId, $fileObjectId, $isNewFile, $userId, $parameters, $parametersAreSigned);
	}

	/**
	 * One half of the autostart: the templates the given parameter set selects.
	 *
	 * Isolated so a test can read the halves a commit raises without firing a real workflow.
	 */
	protected function startAutoBizProcHalf(
		int $storageId,
		int $fileObjectId,
		bool $isNewFile,
		int $userId,
		array $parameters,
		bool $parametersAreSigned
	): void
	{
		if ($isNewFile)
		{
			BizProcDocument::runAfterCreateWithInputParameters(
				$storageId, $fileObjectId, $parameters, $parametersAreSigned, $userId
			);
		}
		else
		{
			BizProcDocument::runAfterEditWithInputParameters(
				$storageId, $fileObjectId, $parameters, $parametersAreSigned, $userId
			);
		}
	}

	/**
	 * The part of the after-create effects that the autostart above does not cover: an
	 * automation start trigger on file creation. The docked write suppresses the domain
	 * ones (DISABLE_AUTO_BIZPROC) to keep them behind the durable commit point, so a file
	 * uploaded here would otherwise never raise a trigger the same file raises through the
	 * ordinary upload. A new VERSION is an Edit and raises nothing.
	 *
	 * The verdict is memoized exactly like {@see self::askBizProcGate()}: the commit asks per
	 * FILE while the answer depends on the storage alone, so a folder of hundreds of files
	 * would otherwise repeat one and the same trigger lookup - and so would a batch that
	 * revisits a storage after moving through another queue.
	 */
	protected function runCreateTrigger(int $storageId, int $fileObjectId, int $userId): void
	{
		if (count($this->createTriggerVerdicts) >= self::MEMO_LIMIT)
		{
			$this->createTriggerVerdicts = [];
		}

		$hasTrigger = $this->createTriggerVerdicts[$storageId] ??= $this->hasFileCreateTrigger($storageId);
		if (!$hasTrigger)
		{
			return;
		}

		BizProcDocument::runCreateTrigger($storageId, $fileObjectId, $userId);
	}

	/**
	 * Isolated so the trigger lookup can be driven from a test without a configured
	 * automation trigger.
	 */
	protected function hasFileCreateTrigger(int $storageId): bool
	{
		return BizProcDocument::hasFileCreateTrigger($storageId);
	}

	/**
	 * B4 / AC-049/AC-051 seam: whether an active parameterized autostart workflow
	 * exists for this storage+event whose required parameters the collected set does
	 * not satisfy. Isolated as a protected method so the commit-path gate can be
	 * exercised deterministically without a live bizproc template.
	 */
	protected function hasUnmetBizProcParameters(
		int $storageId,
		int $autoExecuteType,
		array $bizProcParameters,
		bool $bizProcParametersAreSigned
	): bool
	{
		return BizProcDocument::hasUnmetRequiredAutoStartParameters(
			$storageId,
			$autoExecuteType,
			$bizProcParameters,
			$bizProcParametersAreSigned
		);
	}

	/**
	 * The gate above, asked once per distinct question.
	 *
	 * {@see BizProcDocument::hasUnmetRequiredAutoStartParameters()} reads
	 * b_bp_workflow_template once per document type it walks - two of them - and the commit
	 * path asks it per FILE, so a folder of 500 files spent up to 1000 template reads inside
	 * the single HTTP request the batched updateItems arrives in. The question is the same for every
	 * file of one folder, and its answer depends on nothing else: the same key never
	 * covers a different storage, a different autostart event, a different parameter set
	 * or a different signed flag.
	 *
	 * Its lifetime is the enclosing SCOPE ({@see self::openBizProcGateScope()}), never the
	 * process: this is a singleton of the service locator, and a verdict that outlived its
	 * run would answer for a template state read in another one.
	 */
	private function askBizProcGate(
		int $storageId,
		int $autoExecuteType,
		array $bizProcParameters,
		bool $bizProcParametersAreSigned
	): bool
	{
		$key = self::bizProcGateKey($storageId, $autoExecuteType, $bizProcParameters, $bizProcParametersAreSigned);
		if (count($this->bizProcGateVerdicts) >= self::MEMO_LIMIT)
		{
			$this->bizProcGateVerdicts = [];
		}

		return $this->bizProcGateVerdicts[$key] ??= $this->hasUnmetBizProcParameters(
			$storageId,
			$autoExecuteType,
			$bizProcParameters,
			$bizProcParametersAreSigned
		);
	}

	/**
	 * Declares that the commits about to arrive are ONE run, however many queues they name -
	 * paired with {@see self::closeCommitRun()} in a finally, and reentrant.
	 *
	 * Without it the memo of {@see self::askBizProcGate()} and {@see self::runCreateTrigger()}
	 * lives for one queue, which is right for every caller that walks a queue at a time (the
	 * folder-tree stepper, the cleanup agent, the single updateItem) and wrong for the one
	 * that does not: an updateItems volley may name its rows in any order, queues interleaved,
	 * and there the per-queue scope closes on every row - the memo collapses to nothing
	 * exactly on the batch it was written for (up to 1000 b_bp_workflow_template reads in one
	 * POST of 500 rows). The verdict never depended on which queue asked for it - its key
	 * holds the whole question - so the run is a sound scope for it.
	 *
	 * A run, and not simply no scope at all: the service is a singleton of the service
	 * locator, so a memo without a boundary would answer a later, unrelated run of the same
	 * process from a template state read before it.
	 */
	public function openCommitRun(): void
	{
		if ($this->commitRunDepth === 0)
		{
			$this->bizProcGateVerdicts = [];
			$this->createTriggerVerdicts = [];
			$this->bizProcGateQueueId = null;
		}

		$this->commitRunDepth++;
	}

	/**
	 * Ends the run {@see self::openCommitRun()} opened, dropping what it collected.
	 */
	public function closeCommitRun(): void
	{
		if ($this->commitRunDepth === 0)
		{
			return;
		}

		$this->commitRunDepth--;
		if ($this->commitRunDepth === 0)
		{
			$this->bizProcGateVerdicts = [];
			$this->createTriggerVerdicts = [];
			$this->bizProcGateQueueId = null;
		}
	}

	/**
	 * Opens the memo scope of {@see self::askBizProcGate()} and {@see self::runCreateTrigger()}
	 * for the queue about to be committed, dropping what the previous one collected - unless
	 * the caller has declared a run of its own, which spans the queues by construction.
	 */
	private function openBizProcGateScope(int $queueId): void
	{
		if ($this->commitRunDepth > 0)
		{
			return;
		}

		if ($queueId !== $this->bizProcGateQueueId)
		{
			$this->bizProcGateQueueId = $queueId;
			$this->bizProcGateVerdicts = [];
			$this->createTriggerVerdicts = [];
		}
	}

	/**
	 * Every input the verdict is a function of, in one key.
	 */
	private static function bizProcGateKey(
		int $storageId,
		int $autoExecuteType,
		array $bizProcParameters,
		bool $bizProcParametersAreSigned
	): string
	{
		return implode(':', [
			$storageId,
			$autoExecuteType,
			$bizProcParametersAreSigned ? '1' : '0',
			md5(serialize($bizProcParameters)),
		]);
	}

	/**
	 * Materialises the temp file (durably keeps its b_file) after re-validating
	 * the token owner (Q-5). Returns the b_file id, or null when the item was
	 * failed with an owner/temp error.
	 */
	private function materialize(DockedUploadItem $item, int $userId): ?int
	{
		$tempToken = (string)$item->getTempToken();
		if ($tempToken === '')
		{
			$this->failItem($item, self::ERROR_INVALID_TEMP_FILE);

			return null;
		}

		$pendingFile = $this->uploader->getPendingFiles([$tempToken])->get($tempToken);
		if ($pendingFile === null || !$pendingFile->isValid())
		{
			$this->failItem($item, self::ERROR_INVALID_TEMP_FILE);

			return null;
		}

		$ownerId = $this->resolveTempOwner($pendingFile->getGuid());
		if ($ownerId === null || $ownerId !== $userId)
		{
			$this->failItem($item, self::ERROR_OWNER_MISMATCH);

			return null;
		}

		$pendingFile->makePersistent();

		$bFileId = $pendingFile->getFileId();
		if (!$bFileId)
		{
			$this->failItem($item, self::ERROR_INVALID_TEMP_FILE);

			return null;
		}

		return (int)$bFileId;
	}

	private function resolveTempOwner(?string $guid): ?int
	{
		if ($guid === null || $guid === '')
		{
			return null;
		}

		$row = TempFileTable::getList([
			'select' => ['CREATED_BY'],
			'filter' => ['=GUID' => $guid],
			'limit' => 1,
		])->fetch();

		return $row ? (int)$row['CREATED_BY'] : null;
	}

	/**
	 * R13 (security): the authoritative size is ALWAYS read from the materialised
	 * CFile, never trusting the client-declared items[].size. The declared size is
	 * only used upstream as a fast pre-materialisation quota hint.
	 */
	private function resolveActualSize(int $bFileId): ?int
	{
		$fileArray = \CFile::getFileArray($bFileId);

		return $fileArray ? (int)$fileArray['FILE_SIZE'] : null;
	}

	/**
	 * A missing CFile is a broken temp; a CFile with no bytes is an empty file, told
	 * apart so the user learns the reason instead of being offered a retry that cannot help.
	 */
	private static function classifyActualSize(?int $size): ?string
	{
		return match (true)
		{
			$size === null => self::ERROR_INVALID_TEMP_FILE,
			$size <= 0 => DisplayStatusResolver::ERROR_FILE_EMPTY,
			default => null,
		};
	}

	/**
	 * Durable landing of a transport-reported terminal failure (API-01
	 * updateItem.transportError, belt 2 of mechanism 1).
	 *
	 * The client code is a PROMPT, never the source of truth: every reason is
	 * re-asked here against the server's own state, and the terminal state is
	 * applied only on that verdict. An unconfirmed report degrades to a generic
	 * failure, so a client cannot stop a queue by claiming a quota error nor make
	 * its own item unrepeatable by claiming a restriction.
	 *
	 * The access restriction is asked FIRST, mirroring {@see self::classifyCommitAccessBlock()}:
	 * a user the disk tool is turned off for must be told exactly that, and that
	 * verdict must not depend on whether the target folder resolved or on how much
	 * portal space is left (ERR-015). Such an item lands as ERROR_RESTRICTION - the same
	 * non-retriable code the commit path produces - and the queue keeps running instead of
	 * stopping by quota.
	 *
	 * No orphan b_file to release: nothing persistent was created before the commit,
	 * and the temp b_file whose write failed is exactly what produced the signal.
	 *
	 * @param string|null $reportedCode the transport code the client sent with the report.
	 *   It decides nothing but the WORDING of a failure the server has already refused to
	 *   explain by a restriction or a quota stop: a reported network loss lands as
	 *   ERROR_CONNECTION, anything else as the generic ERROR_INTERNAL. Both are equally
	 *   retriable, so trusting the client here cannot buy it a state it could not reach by
	 *   sending no code at all.
	 */
	public function applyTransportFailure(DockedUploadItem $item, int $userId, ?string $reportedCode = null): void
	{
		if ($this->folderResolver->isAccessRestricted($userId))
		{
			$this->failItem($item, self::ERROR_RESTRICTION);

			return;
		}

		$storage = $this->resolveTargetStorage($item, $userId);

		$stop = $storage === null
			? null
			: $this->quotaScenario->detectForUpload($storage, (int)($item->getSize() ?? 0), $userId)
		;

		if ($stop === null)
		{
			$this->failItem($item, self::classifyReportedCode($reportedCode));

			return;
		}

		$this->applyQuotaStop($item, $stop);
	}

	/**
	 * The stored ERROR_CODE of a transport failure the server found no reason of its own for.
	 */
	private static function classifyReportedCode(?string $reportedCode): string
	{
		return in_array($reportedCode, self::CLIENT_NETWORK_ERROR_CODES, true)
			? self::ERROR_CONNECTION
			: self::ERROR_INTERNAL
		;
	}

	/**
	 * The third interception point of a space shortage: the server-side pre-check taken
	 * when an element STARTS transferring, before a single byte is spent on it.
	 *
	 * It does not replace belt 2 (the client relaying its transport error) but makes the
	 * stop deterministic for the TAIL: after a tab is lost mid-transfer nobody reports
	 * the failure, and without this check the next element would keep transferring into
	 * a portal that has no room for it. The culprit of THIS check is the element that
	 * was about to start; the rest of the queue stays pending behind the stopped queue.
	 *
	 * @return QueueStatus|null the state the queue was left in by a stop that was really
	 *   written down; null when there is no shortage, when there is no resolvable target to
	 *   ask about yet, or when the stop lost the row to a concurrent cancel or commit - in
	 *   that last case nothing was applied, and the caller must carry on as if the pre-check
	 *   had been clear.
	 */
	public function applyQuotaStopBeforeTransfer(DockedUploadItem $item, int $userId): ?QueueStatus
	{
		$storage = $this->resolveTargetStorage($item, $userId);
		if ($storage === null)
		{
			return null;
		}

		$stop = $this->detectStopBeforeTransfer($storage, (int)($item->getSize() ?? 0));
		if ($stop === null)
		{
			return null;
		}

		if (!$this->recordQuotaStop($item, $stop))
		{
			// The guarded terminal update lost the row to a concurrent cancel or commit, so
			// no stop was applied and neither was the queue touched. Reporting one anyway
			// would cost the queue the promotion this transfer still owes it.
			return null;
		}

		// Read back rather than assume: the queue keeps the state its FIRST culprit earned,
		// and one the user has closed is never stopped again.
		return $this->queueRepository->getById((int)$item->getQueueId())?->getStatus();
	}

	/**
	 * The storage this element is heading for, asked the way the fixation itself asks it:
	 * a version element names its destination through TARGET_FILE_ID and may carry no
	 * folder at all ({@see \Bitrix\Disk\Internal\Dto\DockedUpload\ItemDto}), so
	 * {@see self::commitNewVersion()} takes the storage off the target FILE and only
	 * {@see self::commitNewFile()} takes it off the folder.
	 *
	 * The distinction matters to every caller here: resolveTargetFolder(0, ...) is not
	 * "not found" but "no folder given" - it answers the user's OWN storage and its
	 * uploaded-files folder. Measuring the storage size restriction (mechanism 2) there for
	 * an element bound elsewhere would stop the whole queue over a limit its target knows
	 * nothing about, or miss the stop the target really needed. The denormalised STORAGE_ID
	 * on the row is no substitute: its own contract forbids reading it as the truth of where
	 * an element lands.
	 */
	private function resolveTargetStorage(DockedUploadItem $item, int $userId): ?Storage
	{
		$targetFileId = (int)$item->getTargetFileId();
		if ($targetFileId > 0)
		{
			return File::loadById($targetFileId, ['STORAGE'])?->getStorage();
		}

		return $this->folderResolver
			->resolveTargetFolder((int)$item->getTargetFolderId(), $userId)
			?->getStorage()
		;
	}

	/**
	 * The pre-check's own reading of {@see QuotaScenario::detectForUpload()}: the same two
	 * mechanisms in the same order (a storage size restriction outranks the portal-wide
	 * limit), with the storage usage asked once per request instead of once per element.
	 *
	 * {@see Storage::isPossibleToUpload()} sums the size of every version in the storage
	 * subtree on each call and has no cache of its own, so on a portal with the storage size
	 * restriction enabled the pre-check would add that aggregate per element entering a
	 * transfer, growing with the storage. Reading the usage once is enough HERE and only
	 * here: the pre-check is approximate by contract - the commit re-asks the live question
	 * on the authoritative size a moment later ({@see self::commitNewFile()}), and there the
	 * usage must keep growing with every fixation of the batch. The verdict itself stays per
	 * element: each entry is still weighed with its own size.
	 */
	private function detectStopBeforeTransfer(Storage $storage, int $size): ?QuotaStopInfo
	{
		if ($this->exceedsStorageSizeLimit($storage, $size))
		{
			return $this->quotaScenario->boxStop();
		}

		return $this->quotaScenario->detectPortalQuotaStop($size);
	}

	/**
	 * Mirrors {@see Storage::isPossibleToUpload()} over the memoised usage: there is a size
	 * limit on the storage and it has no room left for one more $size.
	 */
	private function exceedsStorageSizeLimit(Storage $storage, int $size): bool
	{
		$limit = $this->readStorageSizeLimit($storage);
		if ($limit === null)
		{
			return false;
		}

		$storageId = (int)$storage->getId();
		$this->preCheckStorageUsage[$storageId] ??= (int)$storage->getRootObject()?->countSizeOfVersions();

		return $limit < $this->preCheckStorageUsage[$storageId] + $size;
	}

	/**
	 * The size limit of $storage in bytes, or null when mechanism 2 is not in play.
	 *
	 * Kept as the one environment seam of the pre-check, in the role
	 * {@see QuotaScenario::isTariffButtonAvailable()} plays for the tariff: the restriction
	 * is gated by a portal option that {@see \Bitrix\Disk\Configuration::isEnabledStorageSizeRestriction()}
	 * memoises for the whole process, so on a stand that starts with it off mechanism 2
	 * cannot be switched on from a test - and {@see Storage} is final, so it cannot be
	 * doubled either.
	 */
	protected function readStorageSizeLimit(Storage $storage): ?int
	{
		$limit = $storage->getSizeLimit();

		return $limit === null ? null : (int)$limit;
	}

	/**
	 * The commit branches answer with the disk object id, and a quota stop never produces
	 * one. Whether the stop was applied at all is only the pre-check's business
	 * ({@see self::recordQuotaStop()}).
	 */
	private function applyQuotaStop(DockedUploadItem $item, QuotaStopInfo $stop, ?int $orphanBFileId = null): ?int
	{
		$this->recordQuotaStop($item, $stop, $orphanBFileId);

		return null;
	}

	/**
	 * Writes a quota stop down: the item lands terminal and the queue is stopped in the
	 * state the classification carries.
	 *
	 * @return bool whether this branch actually applied the stop - false when the guarded
	 *   terminal update lost the row to a concurrent cancel or commit, in which case nothing
	 *   was written at all (no item status, no queue stop, no log).
	 */
	private function recordQuotaStop(DockedUploadItem $item, QuotaStopInfo $stop, ?int $orphanBFileId = null): bool
	{
		// R7: when the stop was detected only after the persistent b_file was
		// materialised (unsized item, or an addFile/addVersion size restriction),
		// release it so the stop leaves no orphaned persistent file behind.
		$this->deleteOrphanBFile($orphanBFileId);

		// R4: guard the transition - a quota stop racing a concurrent cancel (or a
		// durable commit) must NOT resurrect the row as failed nor stop the queue
		// against the user's explicit cancellation, so the side effects (queue stop,
		// log) run only when this branch actually won the row.
		if (!$this->atomicMarkTerminalFailure((int)$item->getId(), ItemStatus::Failed, $stop->getErrorCode()))
		{
			return false;
		}

		$item->setStatus(ItemStatus::Failed)->setErrorCode($stop->getErrorCode());

		// THE single place a stop state is written down. Which state it is comes from the
		// classification, not from here: the cloud tariff limit gives stopped_by_quota
		// (commercial way out), every other shortage requires_action (ADR, quotas).
		// Only a running queue is stopped: a queue already stopped keeps the state - and
		// with it the restore path - its FIRST culprit earned, and one the user closed is
		// never resurrected by a late report. The guard lives in the UPDATE (R4): a
		// concurrent dismiss holds the queue row until its own transaction ends, so a
		// snapshot read here would be stale by the time the write got the lock.
		$this->queueRepository->markStoppedByQuota((int)$item->getQueueId(), $stop->getQueueStatus());

		// AC-060: log the quota stop at the status transition point (backend), not
		// on the client. Metadata only - no file content or user PII.
		$this->logger->info('docked quota stop', [
			'itemId' => (int)$item->getId(),
			'queueId' => (int)$item->getQueueId(),
			'scenario' => $stop->getScenario(),
			'errorCode' => $stop->getErrorCode(),
		]);

		return true;
	}

	private function failItem(DockedUploadItem $item, string $errorCode): ?int
	{
		// R4: guard the transition so a failure branch racing a concurrent cancel
		// (or a durable commit) can never resurrect the row as failed.
		if (!$this->atomicMarkTerminalFailure((int)$item->getId(), ItemStatus::Failed, $errorCode))
		{
			return null;
		}

		$item->setStatus(ItemStatus::Failed)->setErrorCode($errorCode);

		// AC-060: log the item error at the transition point. Metadata only.
		$this->logger->info('docked item failed', [
			'itemId' => (int)$item->getId(),
			'queueId' => (int)$item->getQueueId(),
			'errorCode' => $errorCode,
		]);

		return null;
	}

	/**
	 * Classifies a commit-time access block into its dedicated non-retriable error code
	 * (ERR-015, ERR-016): a missing storage or a denied per-object permission stays
	 * ERROR_PERMISSION, while a personal access restriction and a blocked common storage
	 * each get their own code - that is what lets the display layer name the block the user
	 * actually met instead of a generic permission failure, and raise the item-level
	 * InfoHelper on the one whose way out is commercial (ERROR_COMMON_STORAGE_BLOCKED).
	 * Returns null when the gate is clear.
	 *
	 * Gate order, which {@see FolderResolverService::classifyUploadTarget()} mirrors
	 * exactly so an element rejected at enqueue time carries the code it would have
	 * carried after the transfer: the personal restriction outranks everything (it is a
	 * verdict about the user, not about the object), then the storage must load, then
	 * the per-object permission, and only then the tariff. The tariff comes LAST on
	 * purpose: it is a property of the storage rather than of the caller, so answering
	 * it to someone with no right on the object would tell them the object exists and
	 * sits in common storage. Whoever legitimately meets that block has the right and
	 * still sees the tariff code.
	 *
	 * @param callable(Storage): bool $hasObjectPermission
	 */
	private function classifyCommitAccessBlock(?Storage $storage, int $userId, callable $hasObjectPermission): ?string
	{
		if ($storage === null)
		{
			return self::ERROR_PERMISSION;
		}
		if ($this->folderResolver->isAccessRestricted($userId))
		{
			return self::ERROR_RESTRICTION;
		}
		if (!$hasObjectPermission($storage))
		{
			return self::ERROR_PERMISSION;
		}
		if ($this->folderResolver->isCommonStorageBlocked($storage))
		{
			return self::ERROR_COMMON_STORAGE_BLOCKED;
		}

		return null;
	}

	/**
	 * @return array{STATUS: string, INTENT_TOKEN: ?string, DISK_OBJECT_ID: ?int}|null
	 */
	private function lockItemRow(int $itemId): ?array
	{
		$table = DockedUploadItemTable::getTableName();
		$sql = "SELECT ID, STATUS, INTENT_TOKEN, DISK_OBJECT_ID FROM {$table} WHERE ID = {$itemId} FOR UPDATE";

		$row = Application::getConnection()->query($sql)->fetch();

		return $row ?: null;
	}

	private function atomicMarkCommitted(int $itemId, int $diskObjectId): bool
	{
		$connection = Application::getConnection();
		$table = DockedUploadItemTable::getTableName();
		$committed = ItemStatus::Committed->value;
		$canceled = ItemStatus::Canceled->value;
		$now = $connection->getSqlHelper()->convertToDbDateTime(new DateTime());

		// R4: commit only wins from a non-terminal transport state. Excluding
		// `canceled` (as well as the idempotent `committed`) stops a commit that
		// races a concurrent cancel from resurrecting the item and firing durable
		// side effects against the user's explicit cancellation.
		//
		// ERROR_CODE is cleared in the same statement, symmetric with
		// {@see self::atomicMarkTerminalFailure()}, which always writes it: a row can reach
		// here carrying the code of an earlier stop it has since been retried out of (a
		// queue resume re-runs a quota-stopped item), and a committed row that still names
		// an error would be read literally by the next consumer of the DTO.
		$sql = "
			UPDATE {$table}
			SET STATUS = '{$committed}', DISK_OBJECT_ID = {$diskObjectId}, ERROR_CODE = NULL, UPDATE_TIME = {$now}
			WHERE ID = {$itemId} AND STATUS NOT IN ('{$committed}', '{$canceled}')
		";
		$connection->queryExecute($sql);

		return $connection->getAffectedRowsCount() === 1;
	}

	/**
	 * R4: guarded transition to a terminal state (Failed / DuplicateConflict, and the
	 * clean `canceled` of an auto-skip - the name is about the guard, not about the
	 * outcome). Mirrors {@see self::atomicMarkCommitted()}: it flips STATUS + ERROR_CODE
	 * only from a non-terminal transport state, excluding the durable-terminal
	 * `committed` and the user-terminal `canceled`. A branch that lost the row (0 rows)
	 * must NOT run its side effects nor let the in-memory item pretend the status was
	 * applied.
	 *
	 * Landing is "the row stands where the UPDATE was taking it", not "one row changed"
	 * (P3:75, the same rule the transport CAS in
	 * {@see DockedUploadItemRepository::transitionStatus()} follows). MySQL counts changed
	 * rows rather than matched ones, so a legal repeat - a retried commit request rewriting
	 * `failed` with the same error code inside the same second - touches no column and
	 * reports zero, silently losing the quota stop this branch was about to write down.
	 * Hence the read-back at zero; PostgreSQL counts matched rows and reaches the same
	 * verdict without it.
	 *
	 * @return bool whether exactly one row made the transition
	 */
	private function atomicMarkTerminalFailure(int $itemId, ItemStatus $status, ?string $errorCode): bool
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$table = DockedUploadItemTable::getTableName();
		$statusSql = $helper->forSql($status->value);
		$errorSql = $errorCode === null ? 'NULL' : "'" . $helper->forSql($errorCode) . "'";
		$committed = ItemStatus::Committed->value;
		$canceled = ItemStatus::Canceled->value;
		$now = $helper->convertToDbDateTime(new DateTime());

		$sql = "
			UPDATE {$table}
			SET STATUS = '{$statusSql}', ERROR_CODE = {$errorSql}, UPDATE_TIME = {$now}
			WHERE ID = {$itemId} AND STATUS NOT IN ('{$committed}', '{$canceled}')
		";
		$connection->queryExecute($sql);

		if ($connection->getAffectedRowsCount() === 1)
		{
			return true;
		}

		return $this->standsTerminal($itemId, $status, $errorCode, [$committed, $canceled]);
	}

	/**
	 * The read half of {@see self::atomicMarkTerminalFailure()}: whether the row already
	 * stands exactly where the guarded UPDATE was taking it.
	 *
	 * It re-asks the written columns AND the guard, never mere existence. The guard half is
	 * what keeps the `canceled` target honest: there the excluded status IS the target, so
	 * no matched-but-unchanged row can exist on either DBMS, and re-asking the guard leaves
	 * a race genuinely lost to a user cancel a loss instead of handing the auto-skip branch
	 * its side effects on a row it never won.
	 *
	 * @param string[] $guardedOut statuses the UPDATE refuses to overwrite
	 */
	private function standsTerminal(int $itemId, ItemStatus $status, ?string $errorCode, array $guardedOut): bool
	{
		$query = DockedUploadItemTable::query()
			->setSelect(['ID'])
			->where('ID', $itemId)
			->where('STATUS', $status->value)
			->whereNotIn('STATUS', $guardedOut)
			->setLimit(1)
		;

		if ($errorCode === null)
		{
			$query->whereNull('ERROR_CODE');
		}
		else
		{
			$query->where('ERROR_CODE', $errorCode);
		}

		return is_array($query->fetch());
	}

	private function generateIntentToken(): string
	{
		return bin2hex(random_bytes(16));
	}
}
