<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Helper;
use Bitrix\Mail\Helper\MailboxDirectoryHelper;
use Bitrix\Mail\Helper\Mailbox\MailboxConnector;
use Bitrix\Mail\Internal\Entity\SourceGeneration\Context;
use Bitrix\Mail\Internal\Service\Message\Conversation\ConversationBackfill;
use Bitrix\Mail\Internal\Service\Mailbox\SettingsWriteCoordinator;
use Bitrix\Mail\Internal\Service\SourceGeneration\Matching\CanonicalMessageData;
use Bitrix\Mail\Internal\Service\SourceGeneration\Matching\MessageMatcher;
use Bitrix\Mail\Internals\Entity\MailboxDirectory;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\Internals\MailEntityOptionsTable;
use Bitrix\Mail\Internals\SourceGenerationMatchTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\Result;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;

/**
 * The repeatable preparation of a new physical source of a mailbox and its activation.
 *
 * One call carries the operation one bounded pass forward, and the repetition until the
 * switch belongs to the caller - {@see run()} states that contract.
 *
 * One operation id owns one prepared generation: the unique index over
 * (MAILBOX_ID, OPERATION_ID) makes a repeated run continue the existing generation
 * instead of creating a second one. The stage and the progress of the operation live
 * in the OPTIONS of that generation, so a failure anywhere before the switch keeps the
 * generation PREPARING, keeps the stage and lets the next run of the same operation
 * resume from it. Nothing already imported is thrown away - with one exception, and the
 * source itself asks for it: a folder answering with another epoch takes back the promise
 * the numbers of the import rest on, so the import of that generation is reset and starts
 * over, at a stage and within a budget of its own {@see resetImportedData()}.
 *
 * The incoming delivery stays on the active generation for the whole preparation: the
 * import writes into the prepared generation only, and the route moves to the new
 * source exactly once, inside {@see MailboxMigrationSwitcher::switch()}. A success is reported only
 * after both halves of the switch are confirmed - the pointer of the mailbox and the
 * legacy projection of the connection.
 *
 * Between the import and the switch stands the tail of the transfer
 * ({@see TailAppendService}): the letters the external service failed to carry over are
 * put onto the new source by the module itself. It has to stand there and nowhere else -
 * the attachments of such a letter can only be downloaded while the old source is still
 * the active one, and the appended letters are read back by the delta pass of the switch.
 *
 * The emergency brake and the feature switch are asked at the entry, so a stopped
 * installation neither accepts a new operation nor synchronizes an accepted generation
 * any further. Every pass asks the storage at its entry; the points inside that pass reuse the
 * answer only within a bounded window, and the point in front of the switch does not reuse it
 * at all. This keeps a brake pressed between two passes visible immediately and bounds the cost
 * of repeated checks inside one pass {@see RolloutBrakeReader}.
 *
 * Everything that talks to a mail server sits behind the protected methods below, which
 * is where the boundary of this service really is.
 */
class MigrationService
{
	public const ERROR_OPERATION_INVALID = 'MAIL_SOURCE_GENERATION_OPERATION_INVALID';
	public const ERROR_OPERATION_BUSY = 'MAIL_SOURCE_GENERATION_OPERATION_BUSY';
	public const ERROR_MIGRATION_STOPPED = 'MAIL_SOURCE_GENERATION_MIGRATION_STOPPED';
	public const ERROR_FEATURE_DISABLED = 'MAIL_SOURCE_GENERATION_FEATURE_DISABLED';
	public const ERROR_SHADOW_FAILED = 'MAIL_SOURCE_GENERATION_SHADOW_FAILED';
	public const ERROR_ANOTHER_OPERATION = 'MAIL_SOURCE_GENERATION_ANOTHER_OPERATION';
	public const ERROR_OPERATION_NOT_FOUND = 'MAIL_SOURCE_GENERATION_OPERATION_NOT_FOUND';
	public const ERROR_CONNECTION_UNAVAILABLE = 'MAIL_SOURCE_GENERATION_CONNECTION_UNAVAILABLE';
	public const ERROR_GENERATION_NOT_CREATED = 'MAIL_SOURCE_GENERATION_NOT_CREATED';
	public const ERROR_MAILBOX_NOT_FOUND = 'MAIL_SOURCE_GENERATION_MAILBOX_NOT_FOUND';
	public const ERROR_MAILBOX_NOT_SUPPORTED = 'MAIL_SOURCE_GENERATION_MAILBOX_NOT_SUPPORTED';
	public const ERROR_SNAPSHOT_IDENTITY_CONFLICT = 'MAIL_SOURCE_GENERATION_SNAPSHOT_IDENTITY_CONFLICT';
	public const ERROR_SNAPSHOT_REVISION_CONFLICT = 'MAIL_SOURCE_GENERATION_SNAPSHOT_REVISION_CONFLICT';
	public const ERROR_SNAPSHOT_NOT_SAVED = 'MAIL_SOURCE_GENERATION_SNAPSHOT_NOT_SAVED';
	public const ERROR_FOLDERS_UNAVAILABLE = 'MAIL_SOURCE_GENERATION_FOLDERS_UNAVAILABLE';
	public const ERROR_INCOME_FOLDER_MISSING = 'MAIL_SOURCE_GENERATION_INCOME_FOLDER_MISSING';
	public const ERROR_DIRECTORY_ROLES_AMBIGUOUS = 'MAIL_SOURCE_GENERATION_DIRECTORY_ROLES_AMBIGUOUS';
	public const ERROR_IMPORT_FAILED = 'MAIL_SOURCE_GENERATION_IMPORT_FAILED';
	public const ERROR_FINAL_SYNC_FAILED = 'MAIL_SOURCE_GENERATION_FINAL_SYNC_FAILED';
	public const ERROR_MAILBOX_PROBLEM = 'MAIL_SOURCE_GENERATION_MAILBOX_PROBLEM';
	public const ERROR_OLD_SOURCE_UNAVAILABLE = 'MAIL_SOURCE_GENERATION_OLD_SOURCE_UNAVAILABLE';
	public const WARNING_OLD_SOURCE_UNAVAILABLE = 'MAIL_SOURCE_GENERATION_OLD_SOURCE_UNAVAILABLE';
	public const ERROR_REPLAY_REFUSED = 'MAIL_SOURCE_GENERATION_REPLAY_REFUSED';
	public const ERROR_SWITCH_NOT_CONFIRMED = 'MAIL_SOURCE_GENERATION_SWITCH_NOT_CONFIRMED';
	public const ERROR_SWITCH_AUTHORIZATION_NOT_SAVED = 'MAIL_SOURCE_GENERATION_SWITCH_AUTHORIZATION_NOT_SAVED';
	public const ERROR_CANCEL_REQUESTED = 'MAIL_SOURCE_GENERATION_CANCEL_REQUESTED';
	public const ERROR_CANCEL_TOO_LATE = 'MAIL_SOURCE_GENERATION_CANCEL_TOO_LATE';
	public const ERROR_CANCEL_NOT_SAVED = 'MAIL_SOURCE_GENERATION_CANCEL_NOT_SAVED';

	/**
	 * The tables the operations live in are not built on this portal at all - the transfer has
	 * simply not been rolled out here, which is the state of most of them.
	 *
	 * A word to wait on rather than an accident: nothing on either side is broken, and the same
	 * question answers yes once the update of the module lands. The word stands among these and
	 * not with the class that produces it {@see \Bitrix\Mail\Integration\MailService\HandoverReadiness}
	 * because the vocabulary of this contour is what the other side reads: the guard of theirs
	 * that keeps their free list level with ours walks the sources of this directory alone, and
	 * weighs the letters of every word of it against the constants of this very class
	 * {@see \Bitrix\MailService\Tests\Integration\Lib\Internal\Integration\Mail\MailSideAwaitedTest}.
	 * A word of this kind declared anywhere else is one their guard cannot see.
	 */
	public const ERROR_OPERATION_SCHEMA_MISSING = 'MAIL_SOURCE_GENERATION_OPERATION_SCHEMA_MISSING';

	/**
	 * The readiness of the portal could not be established at all: the schema of the operations
	 * is there and half built, or the reading of the switches threw instead of answering.
	 *
	 * The opposite of the word above and never a wait. A portal shipped whole never reaches this:
	 * an update that stopped halfway, or a store of the options that cannot be read, is trouble
	 * a human has to come and settle, and an attempt of the other side spent on it is spent
	 * rightly. It is also the word their own reading has for the same states - a throw of ours
	 * reaches them as nothing at all rather than as a no
	 * {@see \Bitrix\MailService\Internal\Integration\Mail\ReceivingSideOnThePortal}.
	 */
	public const ERROR_READINESS_UNANSWERED = 'MAIL_SOURCE_GENERATION_READINESS_UNANSWERED';

	/** The connection snapshot of a generation, as the caller passes it and the table stores it. */
	public const CONNECTION_FIELDS = ConnectionSnapshot::STORAGE_FIELDS;

	/**
	 * The system roles the new IMAP source must identify unambiguously through its
	 * SPECIAL-USE flags before the import may start.
	 */
	private const REQUIRED_ROLES = [
		MailboxDirectoryTable::TYPE_OUTCOME,
		MailboxDirectoryTable::TYPE_TRASH,
		MailboxDirectoryTable::TYPE_SPAM,
	];


	private const STATE_STAGE = 'stage';
	/** Folders of the user the operation brought into the target server: [{source, target, created}] */
	public const STATE_FOLDER_PAIRS = 'folder_pairs';
	/** Folders of the user the operation failed to bring there, never tried again: [{source, reason}] */
	public const STATE_FOLDER_NOT_CREATED = 'folder_not_created';
	/** Temporary CREATE failures by folder: [{source, attempts, reason}] */
	public const STATE_FOLDER_CREATE_FAILURES = 'folder_create_failures';
	/** How many times the hand over went back to the tail for folders {@see LateFolderReconciler} */
	public const STATE_FOLDER_RETURNS = 'folder_returns';
	/** The role of the folder the target server lacks, kept with the failure it stopped the operation with */
	public const STATE_MISSING_TARGET_ROLE = 'missing_target_role';
	private const STATE_ERROR = 'error';
	private const STATE_IMPORTED_DIRS = 'imported_dirs';
	private const STATE_IMPORTED_UIDS = 'imported_uids';
	private const STATE_IMPORT_ATTEMPTS = 'import_attempts';
	/** Folder hash => the epoch of the folder the cursor above belongs to */
	private const STATE_IMPORTED_EPOCHS = 'imported_epochs';
	private const STATE_SHADOW_DIRS = 'shadow_dirs';
	private const STATE_SHADOW_UIDS = 'shadow_uids';
	private const STATE_FINGERPRINT_CURSOR = 'fingerprint_cursor';
	/**
	 * How far the reset of an import has walked the matching journal of the generation: the id of
	 * the last journal row it has answered for. An ascending key and not an offset - the rows
	 * behind it are gone by the time the next page is asked for.
	 */
	private const STATE_RESET_CURSOR = 'reset_cursor';
	/**
	 * How far the reconciliation of the late deliveries has walked the letters of the mailbox: the
	 * last one it has answered for {@see LateDeliveryReconciler::reconcile()}. A high water mark
	 * over message ids, so a letter delivered between two attempts of the hand over lands beyond
	 * every page already walked.
	 */
	private const STATE_RECONCILE_CURSOR = 'reconcile_cursor';

	/**
	 * The letter the catching up pass of the replay stopped at, kept for the same reason and
	 * walked the same way {@see ActionReplayService}.
	 */
	private const STATE_REPLAY_CURSOR = 'replay_cursor';

	/**
	 * The data key of a pass that ran out of its budget: the stage stays where it is, the
	 * progress of the pass is stored and the next run of the operation continues from it.
	 * A pass reporting this is not a failure and never reaches the journal as one.
	 *
	 * Public because a stage of the operation living in a service of its own answers by the
	 * same contract {@see TailAppendService::run()}.
	 */
	public const PASS_CONTINUES = 'continues';

	/** How many letters of a folder one shadow batch rehearses */
	private const SHADOW_BATCH_SIZE = 50;

	/** How many letters of a folder one call of the synchronization carries over */
	private const IMPORT_BATCH_SIZE = 200;

	/** How many letters of a folder one pass of the transfer asks the new source for */
	private const IMPORT_PASS_REACH = 5000;

	/** How many letters of the new source one rehearsal pass covers */
	private const SHADOW_PASS_BUDGET = 5000;

	/** How many local messages one pass builds the candidate fingerprints of */
	private const FINGERPRINT_BUDGET = 2000;

	/**
	 * How many physical rows one pass assigns to the first generation of the mailbox. Every other
	 * stage of an operation spends a budget of its own, and this one used to spend none: on a mailbox
	 * with a long history the very first call held a connection for as long as it took to walk four
	 * tables. An unfinished assignment answers with the stage of the initialization, and the next call
	 * of the same operation continues it.
	 *
	 * The number is the room the share of a pass leaves at a realistic cost of a batch; the
	 * deadline stops the pass in any case, and this budget only keeps it from taking on work it
	 * cannot carry.
	 */
	private const G1_INIT_ROW_BUDGET = 4000;

	/**
	 * How long one operation may spend on a stage that walks the physical rows of a mailbox in
	 * pages: the share of a single operation in a pass of the agent, which answers for twenty
	 * operations of the portal within sixty seconds. What a page costs depends on the load of the
	 * database rather than on how many rows are left, so a budget of rows cannot bound such a
	 * stage on its own - the pass of the agent checks its own budget between operations and never
	 * inside one.
	 *
	 * Three stages spend it: the assignment of the first generation, the reset of an import a
	 * cancelled transfer takes back and the creation of missing folders at the acceptance of a
	 * snapshot {@see getAcceptanceCreationDeadline()}. All are bounded by this share and not by the
	 * timeout of a synchronization, which is two orders larger.
	 *
	 * Do not read those three as the whole list of stages with a deadline: {@see reconcileLateDeliveries()}
	 * still measures itself against a share of that synchronization timeout, twenty five times this
	 * one. It predates this share and keeps its own bound until someone measures what it needs.
	 */
	private const OPERATION_PASS_TIME_BUDGET_SECONDS = 3;

	/**
	 * How many rows of the matching journal one pass of a reset of an import answers for. A row of
	 * a letter the import created costs the whole deletion of that letter - its placements, files,
	 * attachments, accesses and chain - so the budget is counted in journal rows and stays well
	 * below the budgets of the plain rows around it.
	 */
	private const RESET_JOURNAL_BUDGET = 1000;

	/**
	 * How many journal rows one page of that walk reads. It is also the bound of the one list the
	 * walk names - the candidate lists of the results it keeps {@see dropMatchDiagnostics()} - and
	 * a letter the import created still costs the whole cascade of its deletion.
	 */
	private const RESET_JOURNAL_PAGE_SIZE = 100;

	/**
	 * How many physical rows of the generation one pass of a reset sweeps after that walk. A
	 * backstop and not the bound of the pass: at a realistic cost of a row the deadline of the
	 * pass runs out long before this many rows are gone {@see getResetPassDeadline()}. What it is
	 * still for is a pass that never reaches its deadline - on a host where a row costs nothing,
	 * an unbounded sweep would hold the connection for as long as the generation is large.
	 */
	private const RESET_SWEEP_BUDGET = 20000;

	/**
	 * How many of them one page of the sweep reads and one statement of it then deletes: the size
	 * of the steppers of the kernel and the same portion the assignment of a generation carries
	 * {@see BackfillService::ASSIGN_BATCH_SIZE}.
	 */
	private const RESET_SWEEP_PAGE_SIZE = 100;

	/**
	 * The share of the lease of the mailbox the reconciliation of the late deliveries may spend.
	 * It runs inside the hand over, between the delta and the switch, and the switch refuses to
	 * move the route of the delivery once the lease is somebody else's - so the pass has to leave
	 * that lease enough room to finish under.
	 */
	private const RECONCILE_TIME_SHARE = 0.25;

	/** The share of the lease the creation of the late folders of the user may spend {@see getLateFolderCreationDeadline()} */
	private const LATE_FOLDER_TIME_SHARE = 0.25;

	/**
	 * And the share of the same lease the catching up pass of the replay may work under.
	 *
	 * The two shares are not slices of one budget: each of them is a deadline counted from the
	 * moment its own step begins, and they follow one another inside the lease. Together they
	 * are half of it, which leaves the delta ahead of them the other half - the delta bounds
	 * itself by the time quota of the sync engine rather than by a share named here.
	 */
	private const REPLAY_TIME_SHARE = 0.25;

	/**
	 * The physical tables a reset of an import sweeps by the generation: table => the key column one
	 * row is addressed by. The folders of the generation are deliberately not among them - the import
	 * starts over into the same folders, with the system roles the user has already picked.
	 */
	private const RESET_SWEPT_TABLES = [
		'b_mail_message_uid' => 'ID',
		'b_mail_message_upload_queue' => 'ID',
		'b_mail_message_delete_queue' => 'PK',
		'b_mail_message_fingerprint' => 'ID',
	];

	private readonly MailboxMigrationSwitcher $mailboxMigrationSwitcher;

	public function __construct(
		private readonly BackfillService $backfill = new BackfillService(),
		private readonly Switcher $switcher = new Switcher(),
		private readonly ContextResolver $contextResolver = new ContextResolver(),
		private readonly MessageMatcher $matcher = new MessageMatcher(),
		private readonly TailAppendService $tail = new TailAppendService(),
		private readonly LateDeliveryReconciler $reconciler = new LateDeliveryReconciler(),
		private readonly ActionReplayService $replay = new ActionReplayService(),
		?MailboxMigrationSwitcher $mailboxMigrationSwitcher = null,
		private readonly LateServiceBinder $serviceBinder = new LateServiceBinder(),
		private readonly ManualMigrationControl $manualControl = new ManualMigrationControl(),
		private readonly RolloutBrakeReader $brakes = new RolloutBrakeReader(),
		private readonly LateFolderReconciler $lateFolders = new LateFolderReconciler(),
		private readonly AcceptanceFolderLedger $acceptanceFolders = new AcceptanceFolderLedger(),
	)
	{
		$this->mailboxMigrationSwitcher = $mailboxMigrationSwitcher ?? new MailboxMigrationSwitcher($this->switcher);
	}

	/**
	 * The stage of the operation, without changing anything. An operation that has not
	 * created its generation yet reports the state of the shared G1 initializer.
	 */
	public function getStage(int $mailboxId, string $operationId): MigrationStage
	{
		$generation = $this->findGeneration($mailboxId, $operationId);

		return $generation === null
			? MigrationStage::from($this->backfill->getState($mailboxId))
			: $this->readStage($generation);
	}

	/**
	 * Accepts a validated connection snapshot without advancing the migration.
	 *
	 * A repeated acceptance of the same operation may rotate credentials while its
	 * generation is still being prepared. All other snapshot fields identify the
	 * physical source and are immutable. The operation lock is shared with run(), so a
	 * credential rotation cannot race the final switch.
	 */
	public function acceptSnapshot(
		int $mailboxId,
		string $operationId,
		ConnectionSnapshot $snapshot,
		string $migratorService = '',
	): Result
	{
		$result = new Result();
		$operationId = trim($operationId);

		if (
			$mailboxId <= 0
			|| $operationId === ''
			|| mb_strlen($operationId) > 64
			|| $operationId === BackfillService::OPERATION_ID
		)
		{
			return $result
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error('An operation of its own is expected', self::ERROR_OPERATION_INVALID))
			;
		}

		if (!$this->lockOperation($mailboxId))
		{
			return $result
				->setData(['stage' => $this->getStage($mailboxId, $operationId)])
				->addError(new Error(
					sprintf('The migration of the mailbox %u is already running', $mailboxId),
					self::ERROR_OPERATION_BUSY,
				))
			;
		}

		try
		{
			$knownStage = $this->safeStage($mailboxId, $operationId);
			if ($knownStage->isFinal())
			{
				// An operation that is over carries nothing over any more, with one exception: the
				// number of the mail service, which the kit may be bringing for the first time.
				$this->fillServiceIdOfFinishedOperation(
					$mailboxId,
					$operationId,
					$snapshot,
					trim($migratorService),
					$knownStage,
				);

				return $result->setData(['stage' => $knownStage]);
			}

			$knownGeneration = $this->findGeneration($mailboxId, $operationId);
			if ($knownGeneration !== null && $this->isCancellationRequested($knownGeneration))
			{
				return $result
					->setData(['stage' => $knownStage])
					->addError(new Error(
						'The migration operation is being cancelled',
						self::ERROR_CANCEL_REQUESTED,
					))
				;
			}

			$refusal = $this->findRolloutRefusal($mailboxId);
			if ($refusal !== null)
			{
				return $result
					->setData(['stage' => $knownStage])
					->addError($refusal)
				;
			}

			$serverType = $this->fetchMailboxServerType($mailboxId);
			if ($serverType !== null && !BackfillService::supportsServerType($serverType))
			{
				return $result
					->setData(['stage' => MigrationStage::Legacy])
					->addError(new Error(
						'The mailbox type does not support source migration',
						self::ERROR_MAILBOX_NOT_SUPPORTED,
					))
				;
			}

			/*
				A repeated acceptance of a written generation records no folders, so it creates none
				either: a folder the user added since is the hand over's {@see reconcileLateFolders()}.
			*/
			$prepared = $this->prepareTargetSnapshotForMailbox(
				$mailboxId,
				$snapshot,
				null,
				self::notCreatedSourcesOf($knownGeneration),
				$knownGeneration === null,
				$this->acceptanceFolderCreator($mailboxId, $operationId),
			);
			if (!$prepared->isSuccess())
			{
				return $prepared->setData(['stage' => $this->safeStage($mailboxId, $operationId)]);
			}

			$imapDirectories = (array)($prepared->getData()[MailboxMigrationSwitcher::RESULT_IMAP_DIRECTORIES] ?? []);
			$folderOutcomes = (array)($prepared->getData()[MailboxMigrationSwitcher::RESULT_FOLDER_OUTCOMES] ?? []);
			if ($knownGeneration === null)
			{
				$folderOutcomes = $this->acceptanceFolders->carryOver($mailboxId, $operationId, $folderOutcomes, $imapDirectories);
			}

			// Before the sync lock is taken: the finalization of the G1 takes that same lock itself
			$this->tryToFinishFirstGeneration($mailboxId);

			/*
				The folder sync of the old source removes the folders its server does not list, and a
				generation written beside a running synchronization may lose them to it. A busy
				mailbox is refused before anything is written, with the answer the other side repeats
				for free.
			*/
			$mailboxLock = $this->switcher->lockMailbox($mailboxId);
			if ($mailboxLock === null)
			{
				return $result
					->setData(['stage' => $this->safeStage($mailboxId, $operationId)])
					->addError(new Error(
						sprintf('The mailbox %u is busy with a synchronization', $mailboxId),
						self::ERROR_OPERATION_BUSY,
					))
				;
			}

			try
			{
				return $this->acceptSnapshotUnderLock(
					$mailboxId,
					$operationId,
					$snapshot,
					trim($migratorService),
					$imapDirectories,
					$folderOutcomes,
					$result,
				);
			}
			finally
			{
				$this->switcher->unlockMailbox($mailboxId, $mailboxLock);
			}
		}
		finally
		{
			$this->unlockOperation($mailboxId);
		}
	}

	/**
	 * One bounded attempt at the G1 of the mailbox ahead of the acceptance. A mailbox with a pointer
	 * never exposes a prepared generation to its regular synchronization, while the one without it
	 * waits for the first pass of the operation otherwise. Whatever the attempt answers, the
	 * acceptance goes on: the first pass finishes the G1 in any case.
	 */
	private function tryToFinishFirstGeneration(int $mailboxId): void
	{
		try
		{
			$assignedRows = 0;
			$this->backfill->ensureFirstGeneration(
				$mailboxId,
				self::G1_INIT_ROW_BUDGET,
				$assignedRows,
				time() + self::OPERATION_PASS_TIME_BUDGET_SECONDS,
			);
		}
		catch (\Throwable $exception)
		{
			AddMessage2Log(
				sprintf(
					'The G1 of the mailbox %u was not finished ahead of the acceptance: %s',
					$mailboxId,
					$exception->getMessage(),
				),
				'mail',
				2,
				false,
			);
		}
	}

	/**
	 * Runs or continues the operation as far as one pass of it gets.
	 *
	 * One call is one bounded pass, not the whole migration. The fingerprints of the local
	 * history and the rehearsal of the matching each spend a budget of letters, and the
	 * import stops on the time quota of the synchronization; a mailbox larger than a pass is
	 * carried over by several calls. A pass that ran out is not a failure: it reports the
	 * stage it stopped at, keeps its cursors and comes back to them.
	 *
	 * The common migration agent repeats the call with the same mailbox and operation id
	 * until the stage is final. The operation and its complete connection snapshot must
	 * have been accepted through {@see acceptSnapshot()} first.
	 *
	 * What the returned stage asks of the caller - three classes, each answered by a
	 * predicate of the stage, so a loop over run() is built without reading this prose:
	 *  - {@see MigrationStage::hasNowhereToGo()} - stop calling. Either the mailbox already
	 *    serves the new source and a repeated call is refused, or it is not an IMAP one and
	 *    gets no generation at all - the latter is reported without an error of its own;
	 *  - {@see MigrationStage::awaitsExternalInput()} - the operation waits for the
	 *    source system to authorize the final switch; call again after the condition has
	 *    changed;
	 *  - any other stage - call again, the operation has more to carry over.
	 *
	 * A failed pass keeps its stage and its progress as well, and the reason is in the
	 * errors of the result: a call repeated once the cause is gone continues from the stage
	 * instead of starting the operation over.
	 *
	 * @return Result Data always carries ['stage' => MigrationStage], even on a failure.
	 */
	public function run(
		int $mailboxId,
		string $operationId,
	): Result
	{
		$result = new Result();

		if ($mailboxId <= 0 || $operationId === '' || $operationId === BackfillService::OPERATION_ID)
		{
			return $result
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error('An operation of its own is expected', self::ERROR_OPERATION_INVALID))
			;
		}

		if (!$this->lockOperation($mailboxId))
		{
			return $result
				->setData(['stage' => $this->getStage($mailboxId, $operationId)])
				->addError(new Error(
					sprintf('The migration of the mailbox %u is already running', $mailboxId),
					self::ERROR_OPERATION_BUSY,
				))
			;
		}

		try
		{
			$before = $this->progressFingerprint($mailboxId, $operationId);
			$outcome = $this->start($mailboxId, $operationId, $result);
			$after = $this->progressFingerprint($mailboxId, $operationId);

			return $outcome->setData($outcome->getData() + [
				'progressed' => $before !== $after,
			]);
		}
		finally
		{
			$this->unlockOperation($mailboxId);
		}
	}

	/**
	 * Persists the source system authorization of the final switch.
	 *
	 * The command never creates or advances an operation. A later agent pass observes the
	 * stored authorization and switches only after every preparation stage has completed.
	 */
	public function authorizeSwitch(int $mailboxId, string $operationId): Result
	{
		$result = new Result();
		$operationId = trim($operationId);

		if (
			$mailboxId <= 0
			|| $operationId === ''
			|| mb_strlen($operationId) > 64
			|| $operationId === BackfillService::OPERATION_ID
		)
		{
			return $result
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error('An operation of its own is expected', self::ERROR_OPERATION_INVALID))
			;
		}

		if (!$this->lockOperation($mailboxId))
		{
			return $result
				->setData(['stage' => $this->getStage($mailboxId, $operationId)])
				->addError(new Error(
					sprintf('The migration of the mailbox %u is already running', $mailboxId),
					self::ERROR_OPERATION_BUSY,
				))
			;
		}

		try
		{
			return (new SettingsWriteCoordinator())->execute(
				$mailboxId,
				fn(): Result => $this->authorizeSwitchUnderLock($mailboxId, $operationId),
			);
		}
		finally
		{
			$this->unlockOperation($mailboxId);
		}
	}

	/**
	 * Joins the caller transaction while the caller owns OperationLock; never commits.
	 */
	public function authorizeSwitchUnderLock(int $mailboxId, string $operationId): Result
	{
		$result = new Result();
		$operationId = trim($operationId);
		if (
			$mailboxId <= 0
			|| $operationId === ''
			|| mb_strlen($operationId) > 64
			|| $operationId === BackfillService::OPERATION_ID
		)
		{
			return $result
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error('An operation of its own is expected', self::ERROR_OPERATION_INVALID))
			;
		}

		$locked = (new SettingsWriteCoordinator())->lockWithinTransaction($mailboxId, true, $operationId);
		if (!$locked->isSuccess())
		{
			return $locked->setData(['stage' => MigrationStage::Legacy]);
		}
		$generation = $this->findGenerationInLockedState($locked->getData(), $operationId);
		if ($generation === null || !ConnectionSnapshot::isCompleteStorage($generation))
		{
			return $result
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error(
					'The migration operation has not accepted a ready mailbox snapshot',
					self::ERROR_OPERATION_NOT_FOUND,
				))
			;
		}

		$stage = $this->readStage($generation);
		if ($stage->isFinal())
		{
			return $result->setData(['stage' => $stage]);
		}

		if ($this->isCancellationRequested($generation))
		{
			return $result
				->setData(['stage' => $stage])
				->addError(new Error(
					'The migration operation is being cancelled',
					self::ERROR_CANCEL_REQUESTED,
				))
			;
		}

		if (!$this->isSwitchAuthorized($generation) || $stage === MigrationStage::AwaitingSwitchAuthorization)
		{
			$options = is_array($generation['OPTIONS'] ?? null) ? $generation['OPTIONS'] : [];
			$options[MailboxSourceGenerationTable::OPTION_FINAL_SWITCH_AUTHORIZED] = 'Y';
			if ($stage === MigrationStage::AwaitingSwitchAuthorization)
			{
				$options[self::STATE_STAGE] = MigrationStage::ReadyToSwitch->value;
				unset($options[self::STATE_ERROR]);
			}
			$updated = MailboxSourceGenerationTable::update((int)$generation['ID'], ['OPTIONS' => $options]);
			$stored = $this->readLockedGenerationById(Application::getConnection(), (int)$generation['ID']);

			if (
				!$updated->isSuccess()
				|| $stored === null
				|| !$this->isSwitchAuthorized($stored)
				|| $this->readStage($stored) === MigrationStage::AwaitingSwitchAuthorization
			)
			{
				return $result
					->setData(['stage' => $stage])
					->addError(new Error(
						'The mailbox switch authorization is not saved',
						self::ERROR_SWITCH_AUTHORIZATION_NOT_SAVED,
					))
				;
			}

			$stage = $this->readStage($stored);
		}

		return $result->setData(['stage' => $stage]);
	}

	/**
	 * Stops future passes and revokes a stored switch authorization atomically.
	 */
	public function requestCancellation(int $mailboxId, string $operationId): Result
	{
		$operationId = trim($operationId);
		if (
			$mailboxId <= 0
			|| $operationId === ''
			|| mb_strlen($operationId) > 64
			|| $operationId === BackfillService::OPERATION_ID
		)
		{
			return (new Result())
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error('An operation of its own is expected', self::ERROR_OPERATION_INVALID))
			;
		}

		if (!$this->lockOperation($mailboxId))
		{
			return (new Result())
				->setData(['stage' => $this->safeStage($mailboxId, $operationId)])
				->addError(new Error(
					sprintf('The migration of the mailbox %u is already running', $mailboxId),
					self::ERROR_OPERATION_BUSY,
				))
			;
		}

		try
		{
			return (new SettingsWriteCoordinator())->execute(
				$mailboxId,
				fn(): Result => $this->requestCancellationUnderLock($mailboxId, $operationId),
			);
		}
		finally
		{
			$this->unlockOperation($mailboxId);
		}
	}

	/**
	 * Joins the caller transaction while the caller owns OperationLock; never commits.
	 */
	public function requestCancellationUnderLock(int $mailboxId, string $operationId): Result
	{
		$result = new Result();
		$operationId = trim($operationId);

		if (
			$mailboxId <= 0
			|| $operationId === ''
			|| mb_strlen($operationId) > 64
			|| $operationId === BackfillService::OPERATION_ID
		)
		{
			return $result
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error('An operation of its own is expected', self::ERROR_OPERATION_INVALID))
			;
		}

		$locked = (new SettingsWriteCoordinator())->lockWithinTransaction($mailboxId, true, $operationId);
		if (!$locked->isSuccess())
		{
			return $locked->setData(['stage' => MigrationStage::Legacy]);
		}
		$generation = $this->findGenerationInLockedState($locked->getData(), $operationId);
		if ($generation === null)
		{
			return $result
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error(
					'The migration operation is not found',
					self::ERROR_OPERATION_NOT_FOUND,
				))
			;
		}

		$stage = $this->readStage($generation);
		$switchWasAuthorized = $this->isSwitchAuthorized($generation);
		if ($stage->isFinal() || (string)$generation['STATUS'] === MailboxSourceGenerationTable::STATUS_ACTIVE)
		{
			return $result
				->setData(['stage' => $stage])
				->addError(new Error(
					'The mailbox switch has already been committed',
					self::ERROR_CANCEL_TOO_LATE,
				))
			;
		}

		if ($this->isCancellationRequested($generation))
		{
			return $result->setData([
				'stage' => $stage,
				'generationId' => (int)$generation['ID'],
				'switchWasAuthorized' => $switchWasAuthorized,
			]);
		}

		$options = is_array($generation['OPTIONS'] ?? null) ? $generation['OPTIONS'] : [];
		$options[MailboxSourceGenerationTable::OPTION_CANCEL_REQUESTED] = 'Y';
		$options[MailboxSourceGenerationTable::OPTION_FINAL_SWITCH_AUTHORIZED] = 'N';
		$updated = MailboxSourceGenerationTable::update((int)$generation['ID'], ['OPTIONS' => $options]);
		$stored = $this->readLockedGenerationById(Application::getConnection(), (int)$generation['ID']);

		if (!$updated->isSuccess() || $stored === null || !$this->isCancellationRequested($stored))
		{
			return $result
				->setData(['stage' => $stage])
				->addError(new Error(
					'The migration cancellation request is not saved',
					self::ERROR_CANCEL_NOT_SAVED,
				))
			;
		}

		return $result->setData([
			'stage' => $stage,
			'generationId' => (int)$generation['ID'],
			'switchWasAuthorized' => $switchWasAuthorized,
		]);
	}

	/**
	 * One bounded cleanup pass of a cancelled prepared generation.
	 */
	public function cleanupCancelledOperation(int $mailboxId, string $operationId): Result
	{
		$result = new Result();

		if (!$this->lockOperation($mailboxId))
		{
			return $result
				->setData(['stage' => $this->safeStage($mailboxId, $operationId), self::PASS_CONTINUES => true])
				->addError(new Error(
					sprintf('The migration of the mailbox %u is already running', $mailboxId),
					self::ERROR_OPERATION_BUSY,
				))
			;
		}

		try
		{
			$generation = $this->findGeneration($mailboxId, $operationId);
			if ($generation === null)
			{
				return $result->setData([
					'stage' => MigrationStage::Legacy,
					'completed' => true,
				]);
			}

			$stage = $this->readStage($generation);
			if ($stage->isFinal() || (string)$generation['STATUS'] === MailboxSourceGenerationTable::STATUS_ACTIVE)
			{
				return $result
					->setData(['stage' => $stage])
					->addError(new Error(
						'The mailbox switch has already been committed',
						self::ERROR_CANCEL_TOO_LATE,
					))
				;
			}

			if (!$this->isCancellationRequested($generation))
			{
				return $result
					->setData(['stage' => $stage])
					->addError(new Error(
						'The migration operation has no cancellation request',
						self::ERROR_CANCEL_NOT_SAVED,
					))
				;
			}

			$generationId = (int)$generation['ID'];
			$this->store($generationId, MigrationStage::ResettingImport);

			// One deadline for the whole pass: the folders below are the tail of the same sweep
			$deadline = $this->getResetPassDeadline();

			/*
				What the pass took back, and not the fact that it was here: an unfinished cancellation
				reports its progress to the same policy an unfinished transfer does {@see RetryPolicy},
				and a cancellation standing still is exactly what that policy exists to notice.
			*/
			$takenBackRows = 0;

			$resetStage = $this->resetImportedData(
				$mailboxId,
				$generationId,
				$deadline,
				$takenBackRows,
				cancellationWasAccepted: true,
			);
			if ($resetStage === MigrationStage::ResettingImport)
			{
				return $result->setData([
					'stage' => $resetStage,
					self::PASS_CONTINUES => true,
					'progressed' => $takenBackRows > 0,
				]);
			}

			if (!$this->sweepGenerationFolders($mailboxId, $generationId, $deadline, $takenBackRows))
			{
				return $result->setData([
					'stage' => $resetStage,
					self::PASS_CONTINUES => true,
					'progressed' => $takenBackRows > 0,
				]);
			}

			Application::getConnection()->queryExecute(sprintf(
				'DELETE FROM b_mail_source_generation_tail_mark WHERE MAILBOX_ID = %u AND GENERATION_ID = %u',
				$mailboxId,
				$generationId,
			));

			$deleted = (new SettingsWriteCoordinator())->execute(
				$mailboxId,
				static fn(): Result => MailboxSourceGenerationTable::delete($generationId),
			);
			if (!$deleted->isSuccess())
			{
				return $result
					->setData(['stage' => $stage, self::PASS_CONTINUES => true])
					->addError(new Error(
						'The cancelled source generation cannot be removed',
						self::ERROR_CANCEL_NOT_SAVED,
					))
				;
			}

			return $result->setData([
				'stage' => $resetStage,
				'completed' => true,
				'progressed' => true,
			]);
		}
		finally
		{
			$this->unlockOperation($mailboxId);
		}
	}

	/**
	 * The pass itself, with the mailbox already held: every entry into the preparation goes
	 * through {@see run()}, so this is where the state of the operation may be read.
	 */
	private function start(
		int $mailboxId,
		string $operationId,
		Result $result,
	): Result
	{
		$completionData = [];
		$generation = $this->findGeneration($mailboxId, $operationId);
		if ($generation === null || !ConnectionSnapshot::isCompleteStorage($generation))
		{
			return $result
				->setData(['stage' => MigrationStage::Legacy])
				->addError(new Error(
					'The migration operation has not accepted a ready mailbox snapshot',
					self::ERROR_OPERATION_NOT_FOUND,
				))
			;
		}

		if ($this->isCancellationRequested($generation))
		{
			return $result
				->setData(['stage' => $this->readStage($generation)])
				->addError(new Error(
					'The migration operation is being cancelled',
					self::ERROR_CANCEL_REQUESTED,
				))
			;
		}

		/*
			The emergency brake comes before initialization or pass work: no operation
			starts and no prepared generation is synchronized any further. A mailbox that
			has already switched is not touched by it - it keeps working on the source it
			was handed over to, and no legacy path returns to the rows of all generations.
		*/
		$refusal = $this->findRolloutRefusal($mailboxId);
		if ($refusal !== null)
		{
			$stage = $this->getStage($mailboxId, $operationId);
			$result->setData(['stage' => $stage]);

			return $stage->isFinal() ? $result : $result->addError($refusal);
		}

		$stage = $this->readStage($generation);
		if ($stage === MigrationStage::ResettingImport)
		{
			$manualState = $this->manualControl->invalidateForResetUnderLock($mailboxId, $operationId);
		}
		else
		{
			$checkpoint = ManualMigrationControl::checkpointForStage($stage);
			$manualState = $checkpoint === null
				? (new Result())->setData(['manualControlled' => false, 'manualWaiting' => false])
				: $this->manualControl->guardUnderLock($mailboxId, $operationId, $checkpoint);
		}
		if (!$manualState->isSuccess())
		{
			return $result
				->setData(['stage' => $stage] + $manualState->getData())
				->addErrors($manualState->getErrors())
			;
		}
		if ((bool)($manualState->getData()['manualWaiting'] ?? false))
		{
			return $result->setData(['stage' => $stage] + $manualState->getData());
		}

		// The first generation of the mailbox comes first, and always through the shared initializer
		$assignedRows = 0;
		$deadline = time() + self::OPERATION_PASS_TIME_BUDGET_SECONDS;
		$initialized = $this->backfill->ensureFirstGeneration(
			$mailboxId,
			self::G1_INIT_ROW_BUDGET,
			$assignedRows,
			$deadline,
		);
		if ($initialized !== BackfillService::STATE_G1_READY)
		{
			return $result->setData([
				'stage' => MigrationStage::from($initialized),
				'progressed' => $assignedRows > 0,
			]);
		}

		$outcome = $this->advance($mailboxId, $operationId, $generation, $manualState->getData());

		return $outcome->setData($outcome->getData() + $manualState->getData());
	}

	private function advance(
		int $mailboxId,
		string $operationId,
		array $generation,
		array $manualState,
	): Result
	{
		$result = new Result();
		$stage = $this->readStage($generation);

		if ($stage->isFinal())
		{
			return $result->setData(['stage' => $stage]);
		}

		$generationId = (int)$generation['ID'];
		$connection = $this->extractConnection($generation);
		$metrics = $this->createMetrics($mailboxId, $generationId, $operationId, $connection);

		try
		{
			$context = $this->contextResolver->resolveForMigrationImport($mailboxId, $generationId);
		}
		catch (\Throwable $exception)
		{
			return $this->stop($metrics, $generationId, $stage, $result, new Error(
				$exception->getMessage(),
				self::ERROR_GENERATION_NOT_CREATED,
			));
		}

		if ($stage === MigrationStage::PreparingG2)
		{
			$ambiguousRoles = $this->findAmbiguousRoles($mailboxId, $context);
			if ($ambiguousRoles !== [])
			{
				return $this->stop($metrics, $generationId, $stage, $result, new Error(
					'The accepted IMAP directory snapshot has missing or ambiguous system folder roles',
					self::ERROR_DIRECTORY_ROLES_AMBIGUOUS,
				));
			}

			$stage = $this->store($generationId, MigrationStage::ShadowMatching, [
				self::STATE_SHADOW_DIRS => [],
				self::STATE_SHADOW_UIDS => [],
			]);
			if ((bool)($manualState['manualControlled'] ?? false))
			{
				return $result->setData(['stage' => $stage]);
			}
		}

		if ($stage === MigrationStage::ShadowMatching)
		{
			$shadow = $this->createMetrics(
				$mailboxId,
				$generationId,
				$operationId,
				$connection,
				MigrationMetrics::SCOPE_SHADOW,
			);

			$rehearsed = $this->runShadowMatching($shadow, $mailboxId, $connection, $context, $generationId);
			if ($this->isContinued($rehearsed))
			{
				$shadow->save();

				return $result->setData(['stage' => $stage]);
			}

			if (!$rehearsed->isSuccess())
			{
				return $this->stop($shadow, $generationId, $stage, $result, ...$rehearsed->getErrors());
			}

			$stage = $this->store($generationId, MigrationStage::Matching, [
				self::STATE_IMPORTED_DIRS => [],
				self::STATE_IMPORTED_UIDS => [],
			]);

			if ((bool)($manualState['manualControlled'] ?? false) || $this->endsPassAfterShadow())
			{
				return $result->setData(['stage' => $stage]);
			}
		}

		if ($stage === MigrationStage::ResettingImport)
		{
			/*
				The import of this generation is being taken back, and the operation goes nowhere
				until it is: a mailbox holding half of an import must never be handed over to the new
				source. One pass takes back what its budget allows - an unfinished reset stays at this
				stage and continues from its cursor, and the import a finished one starts over belongs
				to the next run of the same operation.
			*/
			$takenBackRows = 0;
			$reset = $this->resetImportedData($mailboxId, $generationId, null, $takenBackRows);

			/*
				The sweep of the physical rows writes nothing down - it is resumable without a cursor
				{@see sweepGenerationRows()} - so a pass spent entirely inside it leaves the options of
				the generation exactly as it found them, and the fingerprint the outer pass compares
				{@see run()} would report a pass that deleted thousands of rows as a pass that stood
				still. Ten of those in a row stop the transfer {@see RetryPolicy}.

				Only what was really deleted is reported: the key set here wins over that fingerprint,
				so a pass that took nothing back leaves the answer to it instead of claiming progress.
			*/
			$progress = $takenBackRows > 0 ? ['progressed' => true] : [];

			return $result->setData(['stage' => $reset] + $progress);
		}

		if ($stage === MigrationStage::Matching)
		{
			$imported = $this->importHistory(
				$metrics,
				$mailboxId,
				$operationId,
				$connection,
				$context,
				$generationId,
			);

			/*
				The measurement reads the whole matching journal of the generation, so only the
				stage that ends the run pays for it. A successful import goes on to the switch
				below, and the numbers are taken there - after the delta of that stage, not before.
			*/
			if ($this->isContinued($imported) || !$imported->isSuccess())
			{
				$this->measureImport($metrics);
			}

			if ($this->isContinued($imported))
			{
				$metrics->save();

				return $result->setData(['stage' => $this->continuedStage($imported, $stage)]);
			}

			if (!$imported->isSuccess())
			{
				return $this->stop($metrics, $generationId, $stage, $result, ...$imported->getErrors());
			}

			$stage = $this->store($generationId, MigrationStage::TailAppend);
			if ((bool)($manualState['manualControlled'] ?? false))
			{
				return $result->setData(['stage' => $stage]);
			}
		}

		if ($stage === MigrationStage::TailAppend)
		{
			$tailMetrics = $this->createMetrics(
				$mailboxId,
				$generationId,
				$operationId,
				$connection,
				MigrationMetrics::SCOPE_TAIL,
			);

			$appended = $this->tail->run($context, $connection, $tailMetrics);
			$tailMetrics->save();

			if ($this->isContinued($appended))
			{
				return $result->setData(['stage' => $stage]);
			}

			if (!$appended->isSuccess())
			{
				return $this->stop($metrics, $generationId, $stage, $result, ...$appended->getErrors());
			}

			/*
				The delta pass walks every folder again, so the folders the main load wrote off as
				finished are forgotten. Their cursors are not: the number a folder was carried over
				up to is where the delta of that folder begins, and everything that arrived during
				the transfer is above it - a source numbers a letter it receives higher than every
				letter of that folder before it. Reset, the cursors would make the delta list and
				hand over every folder from its first letter again, at the price of the whole
				folder per hand-over.
			*/
			$latestGeneration = $this->findGenerationById($generationId) ?? $generation;
			$nextStage = $this->isSwitchAuthorized($latestGeneration)
				? MigrationStage::ReadyToSwitch
				: MigrationStage::AwaitingSwitchAuthorization;
			$stage = $this->store($generationId, $nextStage, [
				self::STATE_IMPORTED_DIRS => [],
				// The reconciliation of the stage below walks the journal of the import from its start
				self::STATE_RECONCILE_CURSOR => 0,
				// And the replay of the same stage walks the letters of the mailbox from theirs
				self::STATE_REPLAY_CURSOR => 0,
			]);
			if ((bool)($manualState['manualControlled'] ?? false))
			{
				return $result->setData(['stage' => $stage]);
			}
		}

		if ($stage === MigrationStage::AwaitingSwitchAuthorization)
		{
			$metrics->save();

			return $result->setData(['stage' => $stage]);
		}

		if ($stage === MigrationStage::ReadyToSwitch)
		{
			$latestGeneration = $this->findGenerationById($generationId);
			if ($latestGeneration === null || !$this->isSwitchAuthorized($latestGeneration))
			{
				$stage = $this->store($generationId, MigrationStage::AwaitingSwitchAuthorization);

				return $result->setData(['stage' => $stage]);
			}

			$switched = $this->finalizeAndSwitch(
				$metrics,
				$mailboxId,
				$operationId,
				$generation,
				$context,
				$generationId,
				(int)$generation['REVISION'],
			);
			$this->measureImport($metrics);

			if ($this->isContinued($switched))
			{
				$metrics->save();

				return $result->setData(['stage' => $this->continuedStage($switched, $stage)]);
			}

			if (!$switched->isSuccess())
			{
				return $this->stop($metrics, $generationId, $stage, $result, ...$switched->getErrors());
			}

			$completionData = $switched->getData();

			$stage = $this->store($generationId, MigrationStage::Switched);
			$manualFinished = $this->manualControl->disableUnderLock($mailboxId, $operationId);
			if (!$manualFinished->isSuccess())
			{
				return $result->addErrors($manualFinished->getErrors())->setData(['stage' => $stage]);
			}
			$result->setData($result->getData() + $manualFinished->getData());
		}

		$metrics->save();

		return $result->setData($completionData + ['stage' => $stage]);
	}

	/**
	 * The dry pass over the new source: the matching decides what every letter of it
	 * would mean for the local history, and the outcomes are only counted.
	 *
	 * Nothing is written down about a rehearsed letter: no physical row, no logical
	 * message, no matching result. The letters of a folder go in batches, so a long
	 * folder is observable while it runs, and both the folders a pass has finished and the
	 * letter it stopped at inside the current one are remembered - a repeat of a pass that
	 * broke off in the middle neither counts a letter twice nor rereads it.
	 *
	 * The rehearsal is read in two levels. The envelope and the structure of a letter answer
	 * the routes of the transferable identifier and of the Message-ID, and they answer the
	 * indexed content lookup as well; the body is downloaded only for the letters that got
	 * as far as the comparison of the bodies. What the two levels cost differently is named
	 * in the outcome: a letter matched from the envelope alone would have been checked
	 * against the body by the import too, so the distribution of the rehearsal is an
	 * estimate of the import and not a replay of it.
	 */
	private function runShadowMatching(
		MigrationMetrics $metrics,
		int $mailboxId,
		array $connection,
		Context $context,
		int $generationId,
	): Result
	{
		$result = new Result();

		if (!$this->catchUpFingerprints($generationId, $context))
		{
			return $this->continued($result);
		}

		$engine = $this->openEngine($mailboxId, $connection, $context);
		if ($engine === null)
		{
			return $result->addError(new Error(
				sprintf('The new source of the mailbox %u is unreachable', $mailboxId),
				self::ERROR_SHADOW_FAILED,
			));
		}

		$done = $this->readDoneDirs($generationId, self::STATE_SHADOW_DIRS);
		$cursors = $this->readUidCursors($generationId, self::STATE_SHADOW_UIDS);
		$budget = $this->getShadowPassBudget();

		$dirs = $engine->getDirsHelper();
		$dirs->reloadDirs();

		foreach ($this->inWalkOrder($dirs->getSyncDirs()) as $dir)
		{
			$hash = $dir->getDirMd5();

			if (isset($done[$hash]))
			{
				continue;
			}

			if ($this->mustStopThePass($generationId))
			{
				$this->store($generationId, null, [self::STATE_SHADOW_UIDS => $cursors]);
				$metrics->save();

				return $this->continued($result);
			}

			$path = $dir->getPath();
			$cursor = (int)($cursors[$hash] ?? 0);

			/*
				What is left of the budget is what the source is asked for: the tail beyond it is
				never searched by the server, never read off the socket and never cut into
				batches. Asked for as a whole, the tail of a folder of hundreds of thousands of
				letters is megabytes prepared for a pass that stops after its budget - and the
				peak of a hit is then reached before a single letter is rehearsed.
			*/
			$reach = $this->rehearsableLetters($budget);
			$uids = $this->listUids($engine, $path, $cursor, reach: $reach);

			if ($uids === false)
			{
				return $result->addError(new Error(
					sprintf('The folder %s of the new source is not readable', $path),
					self::ERROR_SHADOW_FAILED,
				));
			}

			$pending = $this->uidsAfter($uids, $cursor, $reach);

			foreach (array_chunk($pending, self::SHADOW_BATCH_SIZE) as $batch)
			{
				/*
					The rehearsal writes nothing to the new source, but it reads the whole budget
					off the mail server - and a pass told to stop has no business spending it.
					Asked between two batches and not inside one, as everywhere else.
				*/
				if ($this->mustStopThePass($generationId))
				{
					$this->store($generationId, null, [self::STATE_SHADOW_UIDS => $cursors]);
					$metrics->save();

					return $this->continued($result);
				}

				$metrics->startBatch();
				$rehearsed = $this->rehearseBatch($metrics, $engine, $context, $path, $batch);
				$metrics->finishBatch();

				if (!$rehearsed)
				{
					return $result->addError(new Error(
						sprintf('The letters of the folder %s of the new source are not readable', $path),
						self::ERROR_SHADOW_FAILED,
					));
				}

				$cursors[$hash] = (int)max($batch);
				$budget -= count($batch);

				if ($budget <= 0)
				{
					$this->store($generationId, null, [self::STATE_SHADOW_UIDS => $cursors]);
					$metrics->save();

					return $this->continued($result);
				}
			}

			$done[$hash] = true;
			unset($cursors[$hash]);
			$this->store($generationId, null, [
				self::STATE_SHADOW_DIRS => array_keys($done),
				self::STATE_SHADOW_UIDS => $cursors,
			]);
			$metrics->save();
		}

		$metrics->measureGenerationRows();
		$metrics->save();

		return $result;
	}

	/**
	 * Rehearses one batch of letters of a folder and counts what the matching would decide
	 * about each of them.
	 *
	 * The envelopes come first, for the whole batch. A letter the routes of the transferable
	 * identifier, of the Message-ID or of the content lookup already answer is counted from
	 * them; only what is left - a candidate found by the content of the envelope, whose
	 * bodies still have to agree - is downloaded, and only that.
	 *
	 * @param int[] $uids
	 * @return bool False when the source stops answering.
	 */
	private function rehearseBatch(
		MigrationMetrics $metrics,
		Helper\Mailbox\Imap $engine,
		Context $context,
		string $dirPath,
		array $uids,
	): bool
	{
		$envelopes = $this->fetchCanonicalMessages($engine, $dirPath, $uids, false);

		if ($envelopes === false)
		{
			return false;
		}

		$undecided = [];

		foreach ($envelopes as $uid => $letter)
		{
			$decision = $this->matcher->previewFromHeaders($context, $letter['canonical'], $letter['reference']);

			if ($decision === null)
			{
				$undecided[] = (int)$uid;

				continue;
			}

			$metrics->countDecision($decision);
		}

		if ($undecided === [])
		{
			return true;
		}

		$letters = $this->fetchCanonicalMessages($engine, $dirPath, $undecided, true);

		if ($letters === false)
		{
			return false;
		}

		foreach ($letters as $letter)
		{
			$metrics->countDecision($this->matcher->preview($context, $letter['canonical'], $letter['reference']));
		}

		return true;
	}

	/**
	 * The outcomes of the real import, read from the results it stored, and the size of
	 * the generation it is filling.
	 */
	private function measureImport(MigrationMetrics $metrics): void
	{
		$metrics->measureImportedResults();
		$metrics->measureGenerationRows();
	}

	/**
	 * The last steps before the mailbox changes hands: the active generation receives
	 * its final synchronization, the delta it brought is imported into the prepared
	 * generation and only then the route of the delivery moves. A letter that arrives
	 * between the main load and the delta is still delivered by the active generation,
	 * with its filters, integrations and notifications, exactly once.
	 *
	 * The three steps are one sequence under the sync lock of the mailbox: a
	 * synchronization that finished between the final one and the switch would deliver
	 * into the generation the mailbox is about to leave, and the delta that was supposed
	 * to carry that letter over has already run.
	 */
	private function finalizeAndSwitch(
		MigrationMetrics $metrics,
		int $mailboxId,
		string $operationId,
		array $generation,
		Context $context,
		int $generationId,
		int $generationRevision,
	): Result
	{
		$snapshot = ConnectionSnapshot::fromStorage($generation);
		$prepared = $this->prepareTargetSnapshotForMailbox($mailboxId, $snapshot, $generationId);
		if (!$prepared->isSuccess())
		{
			return $prepared;
		}

		$preparedSmtp = $prepared->getData()['smtp'] ?? null;
		if (!is_array($preparedSmtp))
		{
			return (new Result())->addError(new Error(
				'The SMTP snapshot was not prepared',
				SmtpSnapshotSwitcher::ERROR_CONNECTION_UNAVAILABLE,
			));
		}

		$lock = $this->switcher->lockMailbox($mailboxId);
		if ($lock === null)
		{
			return (new Result())->addError(new Error(
				sprintf('The mailbox %u is busy with a synchronization', $mailboxId),
				Switcher::ERROR_MAILBOX_LOCKED,
			));
		}

		try
		{
			return $this->handOver(
				$metrics,
				$mailboxId,
				$operationId,
				$context,
				$generationId,
				$generationRevision,
				$lock,
				$preparedSmtp,
				$snapshot,
			);
		}
		finally
		{
			$this->switcher->unlockMailbox($mailboxId, $lock);
		}
	}

	/**
	 * The hand-over itself, with the mailbox already held.
	 *
	 * The lock is a lease of {@see Helper\Mailbox::getTimeout()} seconds, the same one every
	 * synchronization of the module works under, so a delta longer than that lets the regular
	 * synchronization back in. That is why the pass of the delta is bounded as well: what it
	 * cannot finish it hands to the next run of the operation, which takes the lock anew - and
	 * why the lease itself travels to the switch, which refuses to move the route of the
	 * delivery once the lease is somebody else's.
	 *
	 * @param array $lock The lease of the mailbox this sequence works under.
	 */
	private function handOver(
		MigrationMetrics $metrics,
		int $mailboxId,
		string $operationId,
		Context $context,
		int $generationId,
		int $generationRevision,
		array &$lock,
		array $preparedSmtp,
		ConnectionSnapshot $snapshot,
	): Result
	{
		$result = new Result();
		$connection = $snapshot->imap();
		$warnings = [];

		$synchronized = $this->runFinalSyncOfActiveGeneration($mailboxId);
		if (!$synchronized->isSuccess())
		{
			$errorCode = (string)($synchronized->getErrors()[0]->getCode() ?? '');
			if (in_array($errorCode, [self::ERROR_MAILBOX_PROBLEM, self::ERROR_OLD_SOURCE_UNAVAILABLE], true))
			{
				$warnings[] = ['code' => self::WARNING_OLD_SOURCE_UNAVAILABLE];
			}
			else
			{
				return $synchronized;
			}
		}

		/*
			The folders the user created on the old server while the operation waited for this moment
			are known now, and the old server delivers nowhere else until the switch. What was not
			paired yet is brought into the target server, and the operation goes back to the tail for
			its letters instead of switching on this pass.
		*/
		$lateFolders = $this->reconcileLateFolders($mailboxId, $operationId, $context, $generationId, $snapshot);
		if (!$lateFolders->isSuccess() || $this->isContinued($lateFolders))
		{
			return $lateFolders;
		}

		$delta = $this->importHistory(
			$metrics,
			$mailboxId,
			$operationId,
			$connection,
			$context,
			$generationId,
			true,
		);
		if (!$delta->isSuccess() || $this->isContinued($delta))
		{
			return $delta;
		}

		/*
			Here and nowhere earlier: the delivery of the active source is shut out by the lease
			above, its final synchronization has run and the delta has just completed the
			fingerprints over everything it brought. This is the only moment of the operation at
			which the letters of the mailbox and the decisions of the import are one set, so it is
			the moment the decisions taken over an incomplete one are taken back.
		*/
		$reconciled = $this->reconcileLateDeliveries($context, $generationId);
		if (!$reconciled->isSuccess() || $this->isContinued($reconciled))
		{
			return $reconciled;
		}

		/*
			And here, with the pairs finally settled by the reconciliation above: everything the
			user did to their letters while the transfer was running is carried to the new source
			now, because after the switch it is the new source that serves the mailbox and a
			difference between the two would be the user losing what they did.
		*/
		$replayed = $this->replayUserActions($mailboxId, $operationId, $context, $generationId, $connection);
		if (!$replayed->isSuccess() || $this->isContinued($replayed))
		{
			return $replayed;
		}

		/*
			The last question before the point of no return, and the one place the brakes of the
			rollout are asked of the storage itself: everything above is reversible and this is
			not. A pass refused here leaves the operation exactly where it stood - the prepared
			generation, its cursors and the stored authorization of the switch all stay - and the
			next pass takes it from here once the brake is released.
		*/
		$stoppedNow = $this->findFreshRolloutRefusal($mailboxId);
		if ($stoppedNow !== null)
		{
			return $result->addError($stoppedNow);
		}

		/*
			The pointer this operation is about to replace is named explicitly: two
			operations that both saw the same active generation cannot both switch, the
			loser is rejected by the compare-and-set instead of switching on top.
		*/
		$switched = $this->mailboxMigrationSwitcher->switch(
			$mailboxId,
			$operationId,
			$snapshot,
			$generationRevision,
			$this->switcher->getActiveGenerationId($mailboxId),
			$lock,
			$preparedSmtp,
		);

		if (!$switched->isSuccess())
		{
			return $switched;
		}

		if ($warnings !== [])
		{
			$switched->setData($switched->getData() + ['warnings' => $warnings]);
		}

		if (
			$this->switcher->getActiveGenerationId($mailboxId) !== $generationId
			|| !$this->switcher->isProjectionConfirmed($mailboxId, $generationId)
		)
		{
			return $result->addError(new Error(
				sprintf('The activation of the generation %u is not confirmed', $generationId),
				self::ERROR_SWITCH_NOT_CONFIRMED,
			));
		}

		return $switched;
	}

	/**
	 * The known folders of the old server without a pair among the folders of the prepared generation,
	 * brought into the target server {@see LateFolderReconciler}.
	 *
	 * A pass that recorded a new outcome for any folder goes back to the tail, so the letters of those
	 * folders reach the target server before the switch; the return is continued at the stage of the
	 * tail. Past the limit of returns such a folder is written off as not created: one last return walks
	 * its letters into the inbox, and past that the hand over goes on. A folder written off earlier goes
	 * back to the tail the same way when the final synchronization brought letters into it.
	 */
	private function reconcileLateFolders(
		int $mailboxId,
		string $operationId,
		Context $context,
		int $generationId,
		ConnectionSnapshot $snapshot,
	): Result
	{
		$generation = $this->findGenerationById($generationId);
		$options = is_array($generation['OPTIONS'] ?? null) ? $generation['OPTIONS'] : [];
		$returns = (int)($options[self::STATE_FOLDER_RETURNS] ?? 0);

		$reconciled = $this->lateFolders->reconcile(
			$mailboxId,
			$generationId,
			$this->knownActiveDirectories($mailboxId),
			self::notCreatedSourcesOf($generation),
			self::folderCreateFailuresOf($generation),
			$returns,
			fn (): \Bitrix\Mail\Imap => $this->createTargetImapClient($snapshot),
			new TargetFolderCreator($this->getLateFolderCreationDeadline()),
			fn (array $dirs): bool => $this->tail->hasLettersBeyondItsWalks($context, $dirs),
		);
		if (!$reconciled->isSuccess())
		{
			$error = $reconciled->getErrors()[0] ?? null;
			$customData = $error?->getCustomData();
			$source = is_array($customData) ? (string)($customData['source'] ?? '') : '';
			$reason = is_array($customData) ? (string)($customData['reason'] ?? '') : '';
			if (
				$error !== null
				&& (string)$error->getCode() === self::ERROR_CONNECTION_UNAVAILABLE
				&& $source !== ''
				&& $reason !== ''
			)
			{
				$failures = self::incrementFolderCreateFailure(
					self::folderCreateFailuresOf($generation),
					$source,
					$reason,
				);
				$this->store($generationId, null, [
					self::STATE_FOLDER_CREATE_FAILURES => self::folderCreateFailureOptions($failures),
				]);
			}

			return $reconciled;
		}

		$outcomes = $reconciled->getData();
		if ($outcomes['pairs'] === [] && $outcomes['notCreated'] === [] && !$outcomes['returned'])
		{
			return new Result();
		}

		foreach ($outcomes['notCreated'] as $folder)
		{
			MigrationMetrics::journalOperation(
				'folder_not_created',
				$mailboxId,
				$operationId,
				$generationId,
				MigrationStage::ReadyToSwitch->value,
				sprintf('%s (directory %u)', (string)$folder['reason'], (int)$folder['directoryId']),
			);
		}

		$added = self::folderOutcomeOptions($outcomes);
		$progress = [];
		foreach ([self::STATE_FOLDER_PAIRS, self::STATE_FOLDER_NOT_CREATED] as $key)
		{
			if (isset($added[$key]))
			{
				$progress[$key] = array_merge((array)($options[$key] ?? []), $added[$key]);
			}
		}

		if (!$outcomes['returned'])
		{
			$this->store($generationId, null, $progress);

			return new Result();
		}

		// The folders paired now and the ones that could not be, now or earlier: the letters of all are the tail's
		$this->tail->reopenForFolders($generationId, $outcomes['reopen']);
		// A pass the time cut short leaves the rest to the next one, which brings it in within the limit
		$progress[self::STATE_FOLDER_RETURNS] = $outcomes['partial'] ? $returns : $returns + 1;
		$stage = $this->store($generationId, MigrationStage::TailAppend, $progress);

		return (new Result())->setData([self::PASS_CONTINUES => true, 'stage' => $stage]);
	}

	/**
	 * The late folders are created under the lease of the mailbox, which holds its regular
	 * synchronization back: the creation keeps to a share of it, and a pass that creates anything
	 * goes back to the tail without the delta and the steps after it.
	 */
	protected function getLateFolderCreationDeadline(): int
	{
		return time() + max(1, (int)ceil(Helper\Mailbox::getTimeout() * self::LATE_FOLDER_TIME_SHARE));
	}

	/**
	 * The IMAP connection of the target server the hand over creates the late folders of the user over.
	 */
	protected function createTargetImapClient(ConnectionSnapshot $snapshot): \Bitrix\Mail\Imap
	{
		$imap = $snapshot->imap();

		return new \Bitrix\Mail\Imap(
			$imap['SERVER'],
			$imap['PORT'],
			$imap['USE_TLS'] === 'Y' || $imap['USE_TLS'] === 'S',
			$imap['USE_TLS'] === 'Y',
			$imap['LOGIN'],
			$imap['PASSWORD'],
		);
	}

	/**
	 * The system roles of the prepared generation an automatic assignment could not tell
	 * apart: a role held by no folder or by several of them.
	 *
	 * @return string[]
	 */
	private function findAmbiguousRoles(int $mailboxId, Context $context): array
	{
		return $this->findAmbiguousRolesInScope($mailboxId, GenerationScope::fromContext($context));
	}

	private function findAmbiguousRolesInScope(int $mailboxId, GenerationScope $scope): array
	{
		$ambiguous = [];

		foreach (self::REQUIRED_ROLES as $role)
		{
			$count = MailboxDirectoryTable::getCount($scope->apply([
				'=MAILBOX_ID' => $mailboxId,
				'=' . $role => MailboxDirectoryTable::ACTIVE,
			]));

			if ($count !== 1)
			{
				$ambiguous[] = $role;
			}
		}

		return $ambiguous;
	}

	/**
	 * Imports the history of the new source folder by folder. The fingerprints of the
	 * local messages are completed first: without them an existing letter would be
	 * imported as a duplicate instead of being matched.
	 *
	 * A finished folder is remembered, so a repeat of the operation continues with the
	 * folders it has not reached yet.
	 *
	 * @param bool $isDelta True for the pass that runs right before the switch. That one
	 *        knows no finished folder: the active generation goes on delivering between two
	 *        visits of the stage, so a letter may appear in a folder the previous visit
	 *        walked to the end. It keeps the highest number of every folder instead, and
	 *        the next visit asks the server about what came after it.
	 */
	private function importHistory(
		MigrationMetrics $metrics,
		int $mailboxId,
		string $operationId,
		array $connection,
		Context $context,
		int $generationId,
		bool $isDelta = false,
	): Result
	{
		$result = new Result();

		if (!$this->catchUpFingerprints($generationId, $context))
		{
			return $this->continued($result);
		}

		$engine = $this->openEngine($mailboxId, $connection, $context);
		if ($engine === null)
		{
			return $result->addError(new Error(
				sprintf('The new source of the mailbox %u is unreachable', $mailboxId),
				self::ERROR_IMPORT_FAILED,
			));
		}

		$done = $isDelta ? [] : $this->readDoneDirs($generationId, self::STATE_IMPORTED_DIRS);
		$cursors = $this->readUidCursors($generationId, self::STATE_IMPORTED_UIDS);
		$epochs = $this->readUidCursors($generationId, self::STATE_IMPORTED_EPOCHS);

		// The folders of this generation may have appeared during the very same run
		$dirs = $engine->getDirsHelper();
		$dirs->reloadDirs();

		foreach ($this->inWalkOrder($dirs->getSyncDirs()) as $dir)
		{
			$path = $dir->getPath();
			$hash = $dir->getDirMd5();

			if (isset($done[$hash]))
			{
				continue;
			}

			// Asked after the folders already carried over, so a resumed pass pays nothing for them
			if ($this->mustStopThePass($generationId))
			{
				return $this->continued($result);
			}

			$cursor = (int)($cursors[$hash] ?? 0);

			/*
				The letters the previous pass of this folder already carried over are left out
				of the list before it is handed over: the sync asks the server for the envelopes
				of a chunk before it can tell that a letter is here already, so a pass that
				started from the beginning of the folder would spend the whole hit on the part
				that is done. The server is told the same bound and answers the part that is
				left, so the listing of a folder costs the same.

				The delta asks the same way and for the same reason. What tells it apart is that it
				visits every folder again instead of trusting a finished one: the active generation
				goes on delivering between two visits, and what it delivered is above the cursor of
				the folder - a source numbers a letter it receives higher than every letter that
				folder held before.

				How many letters the pass can reach at all goes into the same command, so the
				numbers of the tail beyond it are neither searched by the server nor read and
				held here. A folder longer than the reach is carried over by several passes, the
				way one longer than the time of a hit already is. That bound is what keeps the
				delta inside the lease of the mailbox it holds.
			*/
			$epoch = null;
			$hasMore = false;
			$uids = $this->listUids(
				$engine,
				$path,
				$cursor,
				$epoch,
				$this->getImportPassReach(),
				$hasMore,
			);
			if ($uids === false)
			{
				return $result->addError(new Error(
					sprintf('The folder %s of the new source is not readable', $path),
					self::ERROR_IMPORT_FAILED,
				));
			}

			/*
				The epoch of the folder is asked before the numbers are filtered by the cursor. A
				server that can no longer promise its numbers mean the same letters says so by a new
				UIDVALIDITY, and the numbers of that new epoch start over - below the cursor of the
				previous one. Filtered by it, the folder answers "nothing new", the pass calls it
				carried over and the mailbox is handed to a source that is missing those letters.
			*/
			$knownEpoch = (int)($epochs[$hash] ?? 0);

			if ($epoch !== null && $knownEpoch > 0 && $epoch !== $knownEpoch)
			{
				return $this->restartAfterEpochChange(
					$result,
					$mailboxId,
					$operationId,
					$generationId,
					$path,
					$knownEpoch,
					$epoch,
				);
			}

			if ($epoch !== null && $epoch !== $knownEpoch)
			{
				$epochs[$hash] = $epoch;
				$this->store($generationId, null, [self::STATE_IMPORTED_EPOCHS => $epochs]);
			}

			$pending = $this->uidsAfter($uids, $cursor);

			$metrics->startBatch();
			$reportedBefore = $engine->getClientErrorCount();
			$reached = 0;
			$messageErrors = 0;
			$loaded = $this->carryOver(
				$engine,
				$mailboxId,
				$generationId,
				$path,
				$pending,
				$reached,
				$messageErrors,
			);
			$metrics->finishBatch();

			if (!$loaded)
			{
				if (!$engine->hasStoppedOnTimeQuota() && !$this->mustStopThePass($generationId))
				{
					return $result->addError(new Error(
						sprintf('The import of the folder %s did not complete', $path),
						self::ERROR_IMPORT_FAILED,
					));
				}

				$cursors[$hash] = max($cursor, $reached);
				$this->store($generationId, null, [self::STATE_IMPORTED_UIDS => $cursors]);

				return $this->continued($result);
			}

			/*
				A transfer of a folder goes on after a batch the source refused to hand over
				and still reports that it kept going, so a folder is finished by proof and
				not by that report: the letters left behind are visible in what the client of
				the engine reported, the way the history pass of the regular sync proves its
				coverage.
			*/
			if ($engine->getClientErrorCount() - $messageErrors > $reportedBefore)
			{
				return $result->addError(new Error(
					sprintf('The letters of the folder %s did not load completely', $path),
					self::ERROR_IMPORT_FAILED,
				));
			}

			$carriedUpTo = $pending === [] ? $cursor : max($cursor, (int)max($pending));

			/*
				The folder is reported to hold letters beyond the window, and none of them was named:
				the cursor has nothing to move to, so this folder is neither finished nor continuable.
				That is reported as a failure of the folder - the operation stops and the next run asks
				again - because both other answers are worse. Called carried over, it hands the mailbox
				to a source missing the rest of the folder; called continued, it spins the hand-over on
				the same window forever.
			*/
			if ($hasMore && $pending === [])
			{
				return $result->addError(new Error(
					sprintf('The folder %s reports letters past its window and named none of them', $path),
					self::ERROR_IMPORT_FAILED,
				));
			}

			if ($isDelta)
			{
				// A folder that had nothing new is left as it is: a hand-over visits every folder
				if ($carriedUpTo > $cursor)
				{
					$cursors[$hash] = $carriedUpTo;
					$this->store($generationId, null, [self::STATE_IMPORTED_UIDS => $cursors]);
				}

				/*
					The window of this visit ended before the folder did, and the delta is bounded by
					it like every other pass: the rest of the folder belongs to the next visit, which
					starts at the cursor above. No folder is written off as finished either way, and
					nothing switches while any folder of the new source says there is more.
				*/
				if ($hasMore)
				{
					return $this->continued($result);
				}

				continue;
			}

			/*
				The pass carried over as many letters of the folder as it asked the source for,
				and the folder holds more: the cursor moves to where the answer ended and the
				next run of the operation continues this very folder. Called carried over here,
				the rest of it would never be asked for again.
			*/
			if ($hasMore)
			{
				$cursors[$hash] = $carriedUpTo;
				$this->store($generationId, null, [self::STATE_IMPORTED_UIDS => $cursors]);

				return $this->continued($result);
			}

			/*
				The folder is carried over, and its cursor stays where the last letter of it left
				it: the delta of the hand-over asks this very folder for what arrived after that
				letter, and a folder without a cursor would be listed and handed over whole again.
			*/
			$done[$hash] = true;
			$cursors[$hash] = $carriedUpTo;
			$this->store($generationId, null, [
				self::STATE_IMPORTED_DIRS => array_keys($done),
				self::STATE_IMPORTED_UIDS => $cursors,
			]);
		}

		return $result;
	}

	/**
	 * The folders of the prepared generation in the order a pass of the operation walks them.
	 *
	 * The loading of the folders orders them by nesting level alone, so the order of the
	 * folders of one level would otherwise be left to the index the planner of the database
	 * picked. A pass keeps its progress folder by folder, so it needs an order of its own,
	 * the same on every run.
	 *
	 * @param MailboxDirectory[] $dirs As {@see Helper\MailboxDirectoryHelper::getSyncDirs()} returns them, keyed by path.
	 * @return MailboxDirectory[]
	 */
	private function inWalkOrder(array $dirs): array
	{
		$ordered = array_values($dirs);

		usort(
			$ordered,
			static fn ($left, $right) => [(int)$left->getLevel(), (int)$left->getId()]
				<=> [(int)$right->getLevel(), (int)$right->getId()],
		);

		return $ordered;
	}

	/**
	 * Folders of the prepared generation a pass of the operation has already finished.
	 *
	 * @param string $key The progress of that pass: the import and the rehearsal keep their own.
	 * @return array<string, true> Folder hashes.
	 */
	private function readDoneDirs(int $generationId, string $key): array
	{
		$stored = $this->readProgress($generationId)[$key] ?? [];

		return is_array($stored) ? array_fill_keys(array_map('strval', $stored), true) : [];
	}

	/**
	 * How far into every folder a pass of the operation has come.
	 *
	 * @param string $key The progress of that pass: the import and the rehearsal keep their own.
	 * @return array<string, int> Folder hash => the last uid of it that pass finished with.
	 */
	private function readUidCursors(int $generationId, string $key): array
	{
		$stored = $this->readProgress($generationId)[$key] ?? [];
		$cursors = [];

		foreach (is_array($stored) ? $stored : [] as $hash => $uid)
		{
			$cursors[(string)$hash] = (int)$uid;
		}

		return $cursors;
	}

	/**
	 * Hands the letters of a folder a pass still has to carry over to the synchronization, in
	 * bounded portions.
	 *
	 * The synchronization works by the list it is given: it asks the base about every uid of
	 * it and cuts it into chunks of its own before it fetches the first letter. The whole
	 * rest of a folder in one call therefore pays for the part the hit will never reach - and
	 * on a folder of hundreds of thousands of letters that preparation can hit the memory
	 * limit before a single letter is carried over, which is a state no repetition gets past,
	 * because every next run stops in the same place with the cursor where it was.
	 *
	 * @param int[] $pending Ascending.
	 * @param int $reached The highest uid the synchronization finished with, out.
	 * @return bool False the way {@see Helper\Mailbox\Imap::syncMessages()} reports it - the
	 *         time of the hit running out included, which the caller tells apart by
	 *         {@see Helper\Mailbox\Imap::hasStoppedOnTimeQuota()}, and a stop asked of the
	 *         operation between two portions {@see mustStopThePass()}.
	 */
	private function carryOver(
		Helper\Mailbox\Imap $engine,
		int $mailboxId,
		int $generationId,
		string $dirPath,
		array $pending,
		int &$reached,
		int &$messageErrors,
	): bool
	{
		$reached = 0;
		$messageErrors = 0;
		$total = count($pending);
		$size = $this->getImportBatchSize();

		for ($offset = 0; $offset < $total; $offset += $size)
		{
			// A portion is what a stop costs: the folder is asked between two of them, not inside one
			if ($this->mustStopThePass($generationId))
			{
				return false;
			}

			$portion = array_slice($pending, $offset, $size);
			$attemptCounts = $this->readProgress($generationId)[self::STATE_IMPORT_ATTEMPTS] ?? [];
			$attemptCounts = is_array($attemptCounts) ? $attemptCounts : [];
			$rowIds = [];
			foreach ($portion as $uid)
			{
				$rowIds[(int)$uid] = $this->importAttemptId($generationId, $dirPath, (int)$uid);
			}
			$offered = array_values(array_filter(
				$portion,
				static fn ($uid): bool => !Helper\Mailbox\HistorySyncAttemptService::isQuarantined(
					(int)($attemptCounts[$rowIds[(int)$uid]] ?? 0),
				),
			));

			if ($offered === [])
			{
				$reached = (int)max($portion);

				continue;
			}

			/*
				A whole portion goes in one call, so an empty chunk must not abort the run, and
				a local failure must be reported instead of swallowed: an unfinished uid has to
				come back on the next pass.
			 */
			$loaded = $engine->syncMessages($mailboxId, $dirPath, $offered, false, false, false, true);
			$messageErrors += $engine->getLastMessageClientErrorCount();
			if (!$loaded && $engine->getLastDeferredUids() === [] && !$engine->hasStoppedOnTimeQuota())
			{
				$reached = max($reached, $engine->getLastSyncedUid());

				return false;
			}
			foreach ($engine->getLastCompletedUids() as $uid)
			{
				unset($attemptCounts[$rowIds[(int)$uid]]);
			}
			$retryable = [];
			foreach ($engine->getLastDeferredUids() as $uid)
			{
				$rowId = $rowIds[(int)$uid];
				$count = (int)($attemptCounts[$rowId] ?? 0) + 1;
				$attemptCounts[$rowId] = $count;
				if (!Helper\Mailbox\HistorySyncAttemptService::isQuarantined($count))
				{
					$retryable[] = (int)$uid;
				}
			}
			$this->store($generationId, null, [self::STATE_IMPORT_ATTEMPTS => $attemptCounts]);

			if (!$loaded && $retryable !== [])
			{
				// A portion the time of the hit ran out in still finished the chunks before it
				$reached = max($reached, $engine->getLastSyncedUid());

				return false;
			}

			if (!$loaded && $engine->hasStoppedOnTimeQuota())
			{
				$reached = max($reached, $engine->getLastSyncedUid());

				return false;
			}

			$reached = (int)max($portion);
		}

		return true;
	}

	private function importAttemptId(int $generationId, string $dirPath, int $uid): string
	{
		return sprintf('%u:%s:%u', $generationId, md5($dirPath), $uid);
	}

	/**
	 * @param int[] $uids Ascending, as {@see Helper\Mailbox\Imap::listFolderUids()} returns them.
	 * @param int|null $limit How many of them the caller can reach at all, null for the whole
	 *        tail: what lies beyond a limit is left in the listing instead of being copied out.
	 * @return int[]
	 */
	private function uidsAfter(array $uids, int $cursor, ?int $limit = null): array
	{
		if ($cursor <= 0 && $limit === null)
		{
			return $uids;
		}

		$pending = [];

		foreach ($uids as $uid)
		{
			if ((int)$uid <= $cursor)
			{
				continue;
			}

			$pending[] = (int)$uid;

			if ($limit !== null && count($pending) >= $limit)
			{
				break;
			}
		}

		return $pending;
	}

	/**
	 * How many letters of a folder the rehearsal can still reach with what is left of the
	 * budget of the pass. A batch is spent whole and the budget is only checked after it, so
	 * the reach is the budget rounded up to the batch - and everything within it is a letter
	 * this pass really does rehearse.
	 */
	private function rehearsableLetters(int $budget): int
	{
		$batches = (int)ceil(max($budget, 1) / self::SHADOW_BATCH_SIZE);

		return $batches * self::SHADOW_BATCH_SIZE;
	}

	/**
	 * The progress the operation keeps with its generation.
	 *
	 * @return array<string, mixed>
	 */
	private function readProgress(int $generationId): array
	{
		$generation = $this->findGenerationById($generationId);

		return is_array($generation['OPTIONS'] ?? null) ? $generation['OPTIONS'] : [];
	}

	/**
	 * Builds the candidate fingerprints of the mailbox within the budget of one pass and
	 * keeps the cursor of the walk with the generation, next to the folders the operation
	 * has already carried over.
	 *
	 * Neither the rehearsal nor the import may start on a mailbox that is not covered yet:
	 * a missing fingerprint turns a letter the mailbox already has into a duplicate. An
	 * unfinished walk is therefore a reason to come back, not a failure - and it comes back
	 * to where it stopped instead of to the beginning of the history.
	 *
	 * @return bool The mailbox is covered and the pass may go on.
	 */
	private function catchUpFingerprints(int $generationId, Context $context): bool
	{
		$cursor = (int)($this->readProgress($generationId)[self::STATE_FINGERPRINT_CURSOR] ?? 0);
		$reached = $this->matcher->buildCandidateFingerprints($context, $cursor, $this->getFingerprintBudget());

		if ($reached !== $cursor)
		{
			$this->store($generationId, null, [self::STATE_FINGERPRINT_CURSOR => $reached]);
		}

		return $this->matcher->hasCandidateFingerprints($context);
	}

	/**
	 * Takes back the decisions the import made about letters the active source had not delivered
	 * yet {@see LateDeliveryReconciler}. The letters of the mailbox are walked page by page and
	 * the cursor of the walk lives with the generation, so a pass that runs out of the lease of
	 * the mailbox is continued by the next run of the same operation - which takes the lease
	 * anew, repeats the final synchronization and meets what it delivered above the cursor.
	 */
	private function reconcileLateDeliveries(Context $context, int $generationId): Result
	{
		$result = new Result();
		$deadline = time() + max(1, (int)ceil(Helper\Mailbox::getTimeout() * self::RECONCILE_TIME_SHARE));
		$cursor = (int)($this->readProgress($generationId)[self::STATE_RECONCILE_CURSOR] ?? 0);

		while (true)
		{
			$reached = $this->reconciler->reconcile($context, $cursor, $this->getReconcileBudget());

			if ($reached <= $cursor)
			{
				return $result;
			}

			$cursor = $reached;
			$this->store($generationId, null, [self::STATE_RECONCILE_CURSOR => $cursor]);

			if (time() >= $deadline || $this->mustStopThePass($generationId))
			{
				return $this->continued($result);
			}
		}
	}

	/**
	 * Carries what the user did to their letters over to the new source {@see ActionReplayService}.
	 * The walk, the cursor and the share of the lease are the ones of the reconciliation above,
	 * and they are those for the same reasons. An unfinished pass hands the rest to the next run
	 * of the operation, which repeats the steps before it and continues from the cursor, and no
	 * run reaches the switch while the pass is unfinished.
	 *
	 * The share of the lease reaches the pass itself and not only the walk between its pages: a
	 * page is hundreds of letters and every pair of folders among them is a command to a server
	 * that may take as long as it likes to answer.
	 *
	 * A pass that could not work at all - a new source that cannot be reached, a context that may
	 * not move letters about - ends this run with an error instead of a cursor. The switch is
	 * irreversible and comes right after, so a pass that carried nothing must hold it back: the
	 * user would otherwise get back, in one go, everything they deleted while the transfer ran.
	 */
	private function replayUserActions(
		int $mailboxId,
		string $operationId,
		Context $context,
		int $generationId,
		array $connection,
	): Result
	{
		$result = new Result();
		$metrics = $this->createMetrics(
			$mailboxId,
			$generationId,
			$operationId,
			$connection,
			MigrationMetrics::SCOPE_REPLAY,
		);
		$deadline = time() + $this->getReplayTimeQuota();
		$cursor = (int)($this->readProgress($generationId)[self::STATE_REPLAY_CURSOR] ?? 0);

		try
		{
			while (true)
			{
				$page = $this->replay->replay(
					$context,
					$connection,
					$metrics,
					$cursor,
					$this->getReplayBudget(),
					$deadline,
				);

				if ($page->isRefused())
				{
					$refusal = match ($page->refusal)
					{
						ActionReplayPage::REFUSAL_SOURCE => 'the new source cannot be reached',
						default => 'the context may not move the letters of the mailbox about',
					};

					return $result->addError(new Error(
						sprintf('The catching up pass of the mailbox %u was refused: %s', $mailboxId, $refusal),
						self::ERROR_REPLAY_REFUSED,
					));
				}

				$progressed = $page->cursor > $cursor;

				if ($progressed)
				{
					$cursor = $page->cursor;
					$this->store($generationId, null, [self::STATE_REPLAY_CURSOR => $cursor]);
				}

				if ($page->isExhausted)
				{
					return $result;
				}

				// A page that moved nothing forward is a page that ran out of its time inside
				if (!$progressed || time() >= $deadline || $this->mustStopThePass($generationId))
				{
					return $this->continued($result);
				}
			}
		}
		finally
		{
			// Counted work is stored whichever way the pass ends, an exception of the walk included
			$metrics->save();
		}
	}

	/**
	 * How many results of the matching journal one page of that walk asks about.
	 */
	protected function getReconcileBudget(): int
	{
		return LateDeliveryReconciler::PAGE_SIZE;
	}

	/**
	 * How many letters of the mailbox one page of the replay walk asks about.
	 */
	protected function getReplayBudget(): int
	{
		return ActionReplayService::PAGE_SIZE;
	}

	/**
	 * How long one run of the catching up pass may work, in seconds.
	 */
	protected function getReplayTimeQuota(): int
	{
		return max(1, (int)ceil(Helper\Mailbox::getTimeout() * self::REPLAY_TIME_SHARE));
	}

	/**
	 * How many local messages one pass builds the candidate fingerprints of. A mailbox
	 * larger than that is covered by several passes of the same operation.
	 */
	protected function getFingerprintBudget(): int
	{
		return self::FINGERPRINT_BUDGET;
	}

	/**
	 * How many letters of a folder go to the synchronization in one call. A folder longer
	 * than that is handed over in several of them {@see carryOver()}.
	 */
	protected function getImportBatchSize(): int
	{
		return self::IMPORT_BATCH_SIZE;
	}

	/**
	 * How many letters of a folder one pass of the transfer asks the new source for. A
	 * folder longer than that is carried over by several passes, each continuing where the
	 * previous one stopped - the same way a folder longer than the time of a hit is.
	 *
	 * A pass hardly ever gets this far: the time of a hit runs out on a fraction of that
	 * many letters, every one of them downloaded and saved. What the reach really bounds is
	 * the listing the pass starts with, which costs nothing per letter and would otherwise
	 * be the whole tail of the folder.
	 */
	protected function getImportPassReach(): int
	{
		return self::IMPORT_PASS_REACH;
	}

	/**
	 * How many letters of the new source one rehearsal pass covers. A mailbox larger than
	 * that is rehearsed by several passes, each continuing from the cursor of the folder the
	 * previous one stopped in.
	 */
	protected function getShadowPassBudget(): int
	{
		return self::SHADOW_PASS_BUDGET;
	}

	/** Extension point for a bounded caller that yields after the rehearsal. */
	protected function endsPassAfterShadow(): bool
	{
		return false;
	}

	/**
	 * One migration of a mailbox at a time. A pass keeps cursors of the folders it has
	 * carried over and spends a budget, so a second one beside it would not only do the
	 * work twice: the two would move the cursors of each other. The common agent may start
	 * again before a previous process has fully left, and that repeated attempt must not
	 * start a second pass.
	 *
	 * The lock is taken without waiting: a pass that finds the mailbox busy leaves it to
	 * the one that holds it, and the operation is continued by the next run anyway.
	 */
	protected function lockOperation(int $mailboxId): bool
	{
		return OperationLock::acquire($mailboxId);
	}

	protected function unlockOperation(int $mailboxId): void
	{
		OperationLock::release($mailboxId);
	}

	/**
	 * The folder answers with another epoch than the one its cursor belongs to: the source can no
	 * longer promise that its numbers mean the same letters. Everything this operation has imported
	 * is dropped and the import starts over.
	 *
	 * Why everything and not the one folder. Deleting a letter takes every placement of it, and a
	 * letter this import created in one folder may well be the letter another folder was matched to -
	 * one letter under two labels. Resetting a single folder would therefore have to find every
	 * folder its deletions touched and drop both the mark and the cursor of each, and to find them
	 * the placements have to be read BEFORE the deletion, because afterwards they are gone. One
	 * mistake in that order is silent: the tests stay green and a letter does not arrive. A full
	 * restart has no such order to get wrong.
	 *
	 * What it costs: the new source is downloaded again for this mailbox. What it does not cost: the
	 * letters the mailbox already had. They are matched, not created, so they stay - only the
	 * placements of this generation leave them.
	 *
	 * The stage moves before a single row is taken back, and that order is the point of the stage: a
	 * reset is bounded like every other pass of the operation, so it may well end unfinished, and a
	 * stage that still said "importing" would let the next run walk the folders of a half reset
	 * generation and hand the mailbox over at the end of them.
	 */
	private function restartAfterEpochChange(
		Result $result,
		int $mailboxId,
		string $operationId,
		int $generationId,
		string $dirPath,
		int $knownEpoch,
		int $currentEpoch,
	): Result
	{
		$resetStarted = $this->manualControl->invalidateForResetUnderLock(
			$mailboxId,
			$operationId,
			function () use ($generationId): void {
				$this->store($generationId, MigrationStage::ResettingImport, [
					self::STATE_IMPORTED_DIRS => [],
					self::STATE_IMPORTED_UIDS => [],
					self::STATE_IMPORTED_EPOCHS => [],
					self::STATE_RESET_CURSOR => 0,
				]);
			},
		);
		if (!$resetStarted->isSuccess())
		{
			return $result->addErrors($resetStarted->getErrors())->setData([
				self::PASS_CONTINUES => true,
				'stage' => MigrationStage::ResettingImport,
			] + $resetStarted->getData());
		}

		AddMessage2Log(
			sprintf(
				'The folder %s of the mailbox %u changed its epoch from %u to %u, the import of the'
				. ' generation %u starts over',
				$dirPath,
				$mailboxId,
				$knownEpoch,
				$currentEpoch,
				$generationId,
			),
			'mail',
			2,
			false,
		);

		return $result->setData([
			self::PASS_CONTINUES => true,
			'stage' => $this->resetImportedData($mailboxId, $generationId),
		] + $resetStarted->getData());
	}

	/**
	 * Everything the import of that generation created, taken back within the budget of one pass.
	 *
	 * The matching journal of the generation is walked by its ascending id, and the cursor of that
	 * walk lives with the generation: a pass that ran out of its budget, of its time or of its
	 * process is continued by the next run of the same operation from the page it stopped at.
	 * Nothing is ever accumulated for the whole generation - a page is what one statement names and
	 * one iteration holds - so neither the memory of a pass nor the length of a statement of it
	 * follows the history of the mailbox.
	 *
	 * The journal is what tells one letter from another: a letter of a NEW or an AMBIGUOUS result was
	 * created by the import and goes as a whole - with its attachments, files, accesses, chain and
	 * its own journal row - while a letter of a MATCHED result was in the mailbox before the
	 * migration and only loses the placement of this generation and the candidate list of that
	 * result. A body or an attachment this import completed for such a letter stays: it is the
	 * content of that very letter, and having it is better than not.
	 *
	 * Nothing of the deleted letters was visible to anyone: the import publishes no delivery effects,
	 * and the placements of a preparing generation are hidden by the scope of every screen. That is
	 * true of a letter this import alone answers for, and the walk asks rather than assumes it
	 * {@see findLettersHeldOutsideTheGeneration()}: a letter another generation holds as well keeps
	 * everything but the placement of this one.
	 *
	 * Repeating a finished reset is harmless and costs one empty page of the journal: every
	 * statement of it addresses the generation and not a letter of it, and a page whose rows are
	 * already gone is simply not there anymore.
	 *
	 * @param int|null $deadline The wall clock of the pass, when the caller sweeps anything after
	 *        this reset and the two share one pass. Null asks for a deadline of this reset alone.
	 * @param int|null $takenBackRows Out: how many rows of the generation this pass answered for -
	 *        the journal rows it walked and the physical rows it swept. It is what tells a pass that
	 *        worked from a pass that stood still, because the sweep keeps no cursor and an unfinished
	 *        reset therefore leaves nothing of itself in the options of the generation
	 *        {@see advance()}. Counted the same way the assignment of a generation counts its own
	 *        rows {@see BackfillService::ensureFirstGeneration()}.
	 * @return MigrationStage ResettingImport while anything is left to take back, Matching once the
	 *         generation is empty and the import may start over.
	 */
	private function resetImportedData(
		int $mailboxId,
		int $generationId,
		?int $deadline = null,
		?int &$takenBackRows = null,
		bool $cancellationWasAccepted = false,
	): MigrationStage
	{
		$deadline ??= $this->getResetPassDeadline();
		$takenBackRows = 0;

		if (!$this->dropImportedLetters(
			$mailboxId,
			$generationId,
			$deadline,
			$takenBackRows,
			$cancellationWasAccepted,
		))
		{
			return MigrationStage::ResettingImport;
		}

		if (!$this->sweepGenerationRows($mailboxId, $generationId, $deadline, $takenBackRows))
		{
			return MigrationStage::ResettingImport;
		}

		/*
			The journal goes last and in one statement: every row of it has been answered for by the
			walk above, and the pair the index of the table begins with names them all without
			reading one of them first.
		*/
		Application::getConnection()->queryExecute(sprintf(
			'DELETE FROM b_mail_source_generation_match WHERE MAILBOX_ID = %u AND GENERATION_ID = %u',
			$mailboxId,
			$generationId,
		));

		return $this->store($generationId, MigrationStage::Matching, [
			self::STATE_IMPORTED_DIRS => [],
			self::STATE_IMPORTED_UIDS => [],
			self::STATE_IMPORTED_EPOCHS => [],
			self::STATE_RESET_CURSOR => 0,
			self::STATE_RECONCILE_CURSOR => 0,
			self::STATE_REPLAY_CURSOR => 0,
		]);
	}

	/**
	 * The walk of the matching journal of the generation: the letters the import created are deleted,
	 * the candidate lists of the results it matched are removed, and the cursor moves behind every
	 * page.
	 *
	 * A page is bounded by what is left of the budget. The cursor is what makes the walk resumable
	 * and finite at once: the rows of a page are gone by the time the next one is asked for, and a
	 * row the deletion of its letter left behind is still walked past instead of being read forever.
	 *
	 * @param int $deadline The wall clock this pass may not walk past {@see getResetPassDeadline()}.
	 * @param int $takenBackRows In and out: the rows of the generation the pass has answered for so
	 *        far {@see resetImportedData()}.
	 * @return bool Whether the journal is walked to its end.
	 */
	private function dropImportedLetters(
		int $mailboxId,
		int $generationId,
		int $deadline,
		int &$takenBackRows,
		bool $cancellationWasAccepted,
	): bool
	{
		$cursor = (int)($this->readProgress($generationId)[self::STATE_RESET_CURSOR] ?? 0);
		$budget = $this->getResetJournalBudget();

		while (true)
		{
			$page = SourceGenerationMatchTable::getList([
				'select' => ['ID', 'STATE', 'MESSAGE_ID'],
				'filter' => [
					'=MAILBOX_ID' => $mailboxId,
					'=GENERATION_ID' => $generationId,
					'>ID' => $cursor,
				],
				'order' => ['ID' => 'ASC'],
				'limit' => max(1, min(self::RESET_JOURNAL_PAGE_SIZE, $budget)),
			])->fetchAll();

			if ($page === [])
			{
				return true;
			}

			/** @var array<int, int[]> Letter the import created => the journal rows naming it */
			$created = [];
			$matched = [];

			foreach ($page as $row)
			{
				$cursor = (int)$row['ID'];
				$messageId = (int)$row['MESSAGE_ID'];

				if ($messageId > 0 && (string)$row['STATE'] !== SourceGenerationMatchTable::STATE_MATCHED)
				{
					$created[$messageId][] = $cursor;

					continue;
				}

				$matched[] = $cursor;
			}

			$heldElsewhere = $this->findLettersHeldOutsideTheGeneration($mailboxId, $generationId, $created);
			$deletedMessageIds = [];

			foreach ($created as $messageId => $journalIds)
			{
				/*
					A letter another generation holds as well is no longer the import alone answering for
					it: the placement of that generation is on the screen of the user. What belongs to the
					import here is the placement of its own generation - the sweep below takes it - and the
					letter is left exactly as a matched one is.
				*/
				if (isset($heldElsewhere[$messageId]))
				{
					array_push($matched, ...$journalIds);

					continue;
				}

				// Takes the placements, the attachments, the files, the accesses, the chain and the journal rows
				\CMailMessage::delete($messageId, $mailboxId);
				$deletedMessageIds[] = $messageId;
			}

			(new ConversationBackfill())->removeMessages($mailboxId, $deletedMessageIds);

			/*
				The candidate list of a result hangs on the journal row and not on the letter, so the
				lists of the matched results are named by the rows of this page: their letters stay.
			*/
			$this->dropMatchDiagnostics($mailboxId, $matched);

			$this->store($generationId, null, [self::STATE_RESET_CURSOR => $cursor]);
			$budget -= count($page);
			$takenBackRows += count($page);

			if (
				$budget <= 0
				|| time() >= $deadline
				|| (!$cancellationWasAccepted && $this->mustStopThePass($generationId))
			)
			{
				return false;
			}
		}
	}

	/**
	 * Which of the letters the import created are held by a generation other than the one being
	 * reset - and are therefore letters the reset must not delete.
	 *
	 * Such a letter exists because the identity of a letter is deliberately not bounded by a
	 * generation: the ordinary synchronization looks one up by the hash of its header block, and a
	 * source that hands a copy over unchanged answers the very same hash, so a delivery of the active
	 * source can register its placement on a letter this import created. That placement is on the
	 * screen of the user, and deleting the letter would take it along.
	 *
	 * One indexed query per page of the journal walk and never one per letter: the pair the lookup
	 * begins with - the mailbox and the letter - is the leading pair of an index of the table.
	 *
	 * @param array<int, int[]> $created Letter => the journal rows naming it, one page of the walk.
	 * @return array<int, true> The letters to keep.
	 */
	private function findLettersHeldOutsideTheGeneration(int $mailboxId, int $generationId, array $created): array
	{
		if ($created === [])
		{
			return [];
		}

		$rows = MailMessageUidTable::getList([
			'select' => ['MESSAGE_ID'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'@MESSAGE_ID' => array_keys($created),
				'!=GENERATION_ID' => $generationId,
			],
		])->fetchAll();

		$held = [];
		foreach ($rows as $row)
		{
			$held[(int)$row['MESSAGE_ID']] = true;
		}

		return $held;
	}

	/**
	 * The candidate lists of the named journal rows. They live in the entity data of the mailbox
	 * addressed by the row, and the column holding that address is a string one, so the rows are
	 * named by their values and never by a subquery comparing it with a number.
	 *
	 * One statement for the whole page {@see RESET_JOURNAL_PAGE_SIZE}: the list is bounded by the
	 * page of the walk that calls this and never by the history of the mailbox, which is what
	 * bounds the length of a statement of the reset everywhere else too
	 * {@see sweepGenerationRows()}.
	 *
	 * @param int[] $journalIds One page of the walk, and bounded by it.
	 */
	private function dropMatchDiagnostics(int $mailboxId, array $journalIds): void
	{
		if ($journalIds === [])
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$entityIds = array_map(
			static fn (int $journalId): string => "'" . $helper->forSql((string)$journalId) . "'",
			$journalIds,
		);

		$connection->queryExecute(sprintf(
			"DELETE FROM b_mail_entity_data WHERE MAILBOX_ID = %u AND ENTITY_TYPE = '%s' AND ENTITY_ID IN (%s)",
			$mailboxId,
			$helper->forSql(MailEntityOptionsTable::SOURCE_GENERATION_MATCH_TYPE_NAME),
			implode(', ', $entityIds),
		));
	}

	/**
	 * The physical rows the import of the generation leaves behind once its letters are gone: the
	 * placements of the letters it matched, the queues addressing them and the fingerprints it wrote
	 * for this generation.
	 *
	 * The rows go in pages of keys - a key select bounded by a limit - and one statement deletes
	 * the page by naming those keys {@see RESET_SWEEP_PAGE_SIZE}. The mailbox stays in the
	 * condition beside them: the primary key of the placements and of the upload queue is the pair
	 * of the row and the mailbox, and the row identifier alone is unique only within one mailbox.
	 * The identifiers are escaped rather than trusted, for the same reason the assignment of a
	 * generation escapes them {@see BackfillService::assignPhysicalRows()}.
	 *
	 * No cursor and no bookmark: the filter is self-consuming, the rows of a page are gone by the
	 * time the next one is asked for, so a repeated pass continues by simply asking again.
	 *
	 * A direct statement on purpose - these rows are invisible to the user, so the deletion queue,
	 * the events and the cascades of the ordinary path have nothing to do here.
	 *
	 * Having no bookmark is also why the caller is told how many rows went: nothing of this walk is
	 * written down, so the pass has no other way of telling that it worked {@see resetImportedData()}.
	 *
	 * @param int $takenBackRows In and out: the rows of the generation the pass has answered for so far.
	 * @return bool Whether the generation is swept clean.
	 */
	private function sweepGenerationRows(
		int $mailboxId,
		int $generationId,
		int $deadline,
		int &$takenBackRows,
	): bool
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$budget = $this->getResetSweepBudget();

		foreach (self::RESET_SWEPT_TABLES as $table => $keyColumn)
		{
			while (true)
			{
				$keys = $connection->query(sprintf(
					'SELECT %s FROM %s WHERE MAILBOX_ID = %u AND GENERATION_ID = %u LIMIT %u',
					$keyColumn,
					$table,
					$mailboxId,
					$generationId,
					max(1, min(self::RESET_SWEEP_PAGE_SIZE, $budget)),
				))->fetchAll();

				if ($keys === [])
				{
					break;
				}

				$identifiers = array_map(
					static fn (array $row): string => "'" . $helper->forSql((string)$row[$keyColumn]) . "'",
					$keys,
				);

				/*
					The generation stays in the condition - it is what makes the filter self-consuming,
					and it leaves alone a row another generation took over.
				*/
				$connection->queryExecute(sprintf(
					'DELETE FROM %1$s WHERE MAILBOX_ID = %2$u AND GENERATION_ID = %3$u AND %4$s IN (%5$s)',
					$table,
					$mailboxId,
					$generationId,
					$keyColumn,
					implode(', ', $identifiers),
				));

				$budget -= count($keys);
				$takenBackRows += count($keys);

				if ($budget <= 0 || time() >= $deadline)
				{
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * The folders of the generation, in the same shape and for the same reasons the rows above are
	 * swept in {@see sweepGenerationRows()}: a page of keys, one statement naming the page, and the
	 * folders that went counted for the caller. The key of a folder is an integer of its own, so
	 * the page is a list of numbers and nothing here needs escaping.
	 *
	 * @param int $takenBackRows In and out: the rows of the generation the pass has answered for so far.
	 */
	private function sweepGenerationFolders(
		int $mailboxId,
		int $generationId,
		int $deadline,
		int &$takenBackRows,
	): bool
	{
		$connection = Application::getConnection();
		$budget = $this->getResetSweepBudget();

		while (true)
		{
			$rows = $connection->query(sprintf(
				'SELECT ID FROM b_mail_mailbox_dir WHERE MAILBOX_ID = %u AND GENERATION_ID = %u LIMIT %u',
				$mailboxId,
				$generationId,
				max(1, min(self::RESET_SWEEP_PAGE_SIZE, $budget)),
			))->fetchAll();

			if ($rows === [])
			{
				return true;
			}

			$identifiers = array_map(static fn (array $row): int => (int)$row['ID'], $rows);

			$connection->queryExecute(sprintf(
				'DELETE FROM b_mail_mailbox_dir WHERE MAILBOX_ID = %u AND GENERATION_ID = %u AND ID IN (%s)',
				$mailboxId,
				$generationId,
				implode(', ', $identifiers),
			));

			$budget -= count($rows);
			$takenBackRows += count($rows);

			if ($budget <= 0 || time() >= $deadline)
			{
				return false;
			}
		}
	}

	/**
	 * How many rows of the matching journal one pass of a reset answers for. A generation with more
	 * of them is taken back by several passes of the same operation, each continuing from the cursor
	 * of the previous one.
	 */
	protected function getResetJournalBudget(): int
	{
		return self::RESET_JOURNAL_BUDGET;
	}

	/**
	 * How many physical rows of the generation one pass of a reset sweeps once its letters are gone.
	 */
	protected function getResetSweepBudget(): int
	{
		return self::RESET_SWEEP_BUDGET;
	}

	/**
	 * The wall clock one pass of a reset of an import may not walk past. It is the share of a
	 * single operation in a pass of the agent, the same the assignment of a generation takes: the
	 * reset walks the rows of a mailbox in pages too, and the pass of the agent answers for twenty
	 * operations of the portal while checking its own budget only between them.
	 */
	protected function getResetPassDeadline(): int
	{
		return time() + self::OPERATION_PASS_TIME_BUDGET_SECONDS;
	}

	/**
	 * A pass that has spent its budget: what it did is stored, what is left belongs to the
	 * next run of the same operation.
	 */
	private function continued(Result $result): Result
	{
		return $result->setData([self::PASS_CONTINUES => true]);
	}

	private function isContinued(Result $result): bool
	{
		return (bool)($result->getData()[self::PASS_CONTINUES] ?? false);
	}

	/**
	 * The stage a continued pass leaves the operation at: the one it was entered with, unless the
	 * pass moved the stage itself. A pass that found the source on another epoch did move it - the
	 * import of the generation is being taken back - and the caller has to hear that stage instead.
	 */
	private function continuedStage(Result $pass, MigrationStage $stage): MigrationStage
	{
		$moved = $pass->getData()['stage'] ?? null;

		return $moved instanceof MigrationStage ? $moved : $stage;
	}

	/**
	 * The uids a folder of the new source holds after $afterUid, over the connection the
	 * engine of the pass already holds.
	 *
	 * @param int $afterUid Where the walk of this folder has come to, 0 for the whole folder.
	 * @param int|null $reach How many letters this pass can reach at all: the bound goes into
	 *        the search command, so neither the server nor this process ever handles the tail
	 *        of a folder of hundreds of thousands of letters for a pass of a few thousand.
	 * @param bool|null $hasMore Receives whether the folder holds letters beyond the answer.
	 * @return int[]|false
	 */
	protected function listUids(
		Helper\Mailbox\Imap $engine,
		string $dirPath,
		int $afterUid = 0,
		?int &$uidValidity = null,
		?int $reach = null,
		?bool &$hasMore = null,
	)
	{
		return $engine->listFolderUids($dirPath, $afterUid, $uidValidity, $reach, $hasMore);
	}

	/**
	 * The canonical view of the letters the new source keeps in a folder, for the shadow
	 * pass: nothing of them is written down.
	 *
	 * @param bool $withBody False leaves the parts of a letter on the server.
	 * @param int[] $uids
	 * @return array<int, array{canonical: CanonicalMessageData, reference: string}>|false
	 */
	protected function fetchCanonicalMessages(
		Helper\Mailbox\Imap $engine,
		string $dirPath,
		array $uids,
		bool $withBody = true,
	)
	{
		return $engine->listCanonicalMessages($dirPath, $uids, $withBody);
	}

	/**
	 * The observability of one pass of the operation. The credentials of the source are
	 * handed over so that no journal entry of the pass can spell them out.
	 */
	protected function createMetrics(
		int $mailboxId,
		int $generationId,
		string $operationId,
		array $connection,
		string $scope = MigrationMetrics::SCOPE_IMPORT,
	): MigrationMetrics
	{
		$metrics = new MigrationMetrics($mailboxId, $generationId, $operationId, $scope);
		$metrics->hideSecretsOf($connection);

		return $metrics;
	}

	/**
	 * Why the rollout does not let the portal take a hand-over kit at all, if it does not.
	 *
	 * Public because the readiness of the portal is a contract of the module towards
	 * mailservice: it is asked before the credentials of a new mailbox are ordered, and
	 * such a question names no mailbox of its own.
	 */
	public function findPortalRefusal(): ?Error
	{
		return $this->findRolloutRefusal(0);
	}

	/**
	 * Why the rollout does not let the operation run at all, if it does not.
	 *
	 * Whether it does is decided by the service of the first generation
	 * {@see BackfillService::findMigrationRefusal()} - the conditions and their order are its
	 * answer, and this method only gives the refusal the error code an operation reports. That
	 * direction and not the other one: the migration already depends on that service, and asking
	 * it back would close the ring.
	 *
	 * A refusal it does not know the code of is a programming error and says so at once: a
	 * condition of the rollout without a code of its own would otherwise be reported under a
	 * borrowed one, and the classification of the retries reads exactly that code.
	 *
	 * @param int $mailboxId Zero asks the same conditions about the portal alone.
	 */
	private function findRolloutRefusal(int $mailboxId): ?Error
	{
		return $this->refusalError($mailboxId, $this->brakes->findRefusalNow());
	}

	/**
	 * The last question before the switch. It deliberately bypasses the bounded reuse inside
	 * the pass, even though the entry of the pass also asked the storage directly.
	 */
	private function findFreshRolloutRefusal(int $mailboxId): ?Error
	{
		return $this->refusalError($mailboxId, $this->brakes->findRefusalNow());
	}

	/**
	 * @param string|null $refusal One of the BackfillService::REFUSAL_* names.
	 */
	private function refusalError(int $mailboxId, ?string $refusal): ?Error
	{
		$subject = $mailboxId > 0 ? sprintf('the mailbox %u', $mailboxId) : 'the portal';

		return match ($refusal)
		{
			BackfillService::REFUSAL_MIGRATION_STOPPED => new Error(
				$this->stopMessage($subject, 'the manual emergency switch'),
				self::ERROR_MIGRATION_STOPPED,
			),
			RolloutBrakeReader::REFUSAL_MAILHOST_STOPPED => new Error(
				$this->stopMessage($subject, 'Mailhost'),
				self::ERROR_MIGRATION_STOPPED,
			),
			BackfillService::REFUSAL_FEATURE_DISABLED => new Error(
				sprintf('The source generations of %s are switched off', $subject),
				self::ERROR_FEATURE_DISABLED,
			),
			null => null,
		};
	}

	private function stopMessage(string $subject, string $source): string
	{
		$details = [];
		if ($this->brakes->getMailhostRevision() !== null)
		{
			$details[] = sprintf('revision %u', $this->brakes->getMailhostRevision());
		}
		if ($this->brakes->getMailhostErrorCode() !== null)
		{
			$details[] = 'snapshot refresh failed: ' . $this->brakes->getMailhostErrorCode();
		}

		return sprintf(
			'The migration of %s is stopped by %s%s',
			$subject,
			$source,
			$details === [] ? '' : ' (' . implode('; ', $details) . ')',
		);
	}

	/**
	 * The synchronization of the active generation, which keeps serving the incoming
	 * delivery until the switch. It runs under the mailbox lock the hand-over already
	 * holds and gives that lock back untouched.
	 *
	 * A pass of the synchronization answers with a count of letters, and five different
	 * refusals to start answer with a count of none - a denied license, a busy mailbox, an
	 * exhausted time quota, a lost database lock and a generation that may not be
	 * synchronized. So the outcome is not read off that count: the engine is asked which
	 * of the five it refused by, and a pass that did start is confirmed by the mark the
	 * mailbox writes about its synchronization, which no refusal ever reaches.
	 *
	 * A mailbox already marked problematic is refused before the pass, without touching
	 * the mail server: its source has been failing to answer for a while, and a hand-over
	 * must not be decided by one more attempt of it. The state is only read here - the
	 * cached connection status of {@see Helper\Mailbox\MailboxSyncManager} writes.
	 */
	protected function runFinalSyncOfActiveGeneration(int $mailboxId): Result
	{
		$result = new Result();
		$state = new Helper\Mailbox\ProblemMailboxStateService();

		if ($state->isProblemMailbox($mailboxId))
		{
			return $result->addError(new Error(
				sprintf('The source of the mailbox %u is marked as failing to answer', $mailboxId),
				self::ERROR_MAILBOX_PROBLEM,
			));
		}

		$engine = $this->createActiveGenerationEngine($mailboxId);
		if ($engine === null)
		{
			return $result->addError(new Error(
				sprintf('The active generation of the mailbox %u has no synchronization engine', $mailboxId),
				self::ERROR_FINAL_SYNC_FAILED,
			));
		}

		$recordedBefore = $state->getSyncStatusTime($mailboxId);

		$count = $engine->syncUnderHeldMailboxLock();

		$refusal = $engine->getLastSyncRefusal();
		if ($refusal !== null)
		{
			return $result->addError(new Error(
				sprintf('The final synchronization of the mailbox %u refused to start', $mailboxId),
				$refusal,
			));
		}

		/*
			The text of an error of the engine is never carried over: a server that rejected a
			login quotes the command it rejected, credentials included.
		*/
		if ($count === false || !$engine->getErrors()->isEmpty())
		{
			$errors = $engine->getErrors()->toArray();
			$onlyUnavailableSourceErrors = $errors !== [];
			foreach ($errors as $error)
			{
				if (!in_array((int)$error->getCode(), [\Bitrix\Mail\Imap::ERR_CONNECT, \Bitrix\Mail\Imap::ERR_AUTH], true))
				{
					$onlyUnavailableSourceErrors = false;

					break;
				}
			}
			if ($onlyUnavailableSourceErrors)
			{
				return $result->addError(new Error(
					sprintf('The old source of the mailbox %u is unavailable', $mailboxId),
					self::ERROR_OLD_SOURCE_UNAVAILABLE,
				));
			}

			return $result->addError(new Error(
				sprintf('The final synchronization of the mailbox %u did not complete', $mailboxId),
				self::ERROR_FINAL_SYNC_FAILED,
			));
		}

		if (!$this->hasRecordedThePass($state, $engine, $recordedBefore))
		{
			return $result->addError(new Error(
				sprintf('The final synchronization of the mailbox %u is not confirmed', $mailboxId),
				self::ERROR_FINAL_SYNC_FAILED,
			));
		}

		return $result;
	}

	/**
	 * Whether the mailbox wrote down a state of its synchronization for the pass that has
	 * just run.
	 *
	 * The mark moves the moment a pass gets past the last of the refusals, so it is the
	 * positive proof that the pass really started. Its resolution is a second, so a pass
	 * that both ran and finished inside the very second of the previous mark is reported
	 * as unconfirmed - the hand-over is then postponed to the next run of the operation,
	 * which is the safe side of that doubt.
	 *
	 * A mailbox nobody owns writes no such mark at all, and for it the report of the
	 * engine is the whole answer - it has been read by the caller already.
	 */
	private function hasRecordedThePass(
		Helper\Mailbox\ProblemMailboxStateService $state,
		Helper\Mailbox $engine,
		?int $recordedBefore,
	): bool
	{
		if ($engine->getMailboxOwnerId() <= 0)
		{
			return true;
		}

		$recordedAfter = $state->getSyncStatusTime($engine->getMailboxId());

		return $recordedAfter !== null && ($recordedBefore === null || $recordedAfter > $recordedBefore);
	}

	/**
	 * The sync engine of the generation serving the mailbox right now, built from the
	 * connection fields of the mailbox the way every regular synchronization builds it.
	 */
	protected function createActiveGenerationEngine(int $mailboxId): ?Helper\Mailbox
	{
		$engine = Helper\Mailbox::createInstance($mailboxId, false);

		return $engine instanceof Helper\Mailbox ? $engine : null;
	}

	/**
	 * The sync engine of the prepared generation, with the matcher of the operation handed
	 * over: the engine guards every imported letter by the readiness of the candidate
	 * fingerprints, and the operation has already established it.
	 */
	private function openEngine(int $mailboxId, array $connection, Context $context): ?Helper\Mailbox\Imap
	{
		$engine = $this->createEngine($mailboxId, $connection, $context);
		$engine?->useMessageMatcher($this->matcher);

		return $engine;
	}

	/**
	 * The sync engine of the prepared generation: it talks to the new source and writes
	 * into that generation only.
	 */
	protected function createEngine(int $mailboxId, array $connection, Context $context): ?Helper\Mailbox\Imap
	{
		$engine = Helper\Mailbox::createGenerationInstance($mailboxId, $context, $connection);

		return $engine instanceof Helper\Mailbox\Imap ? $engine : null;
	}

	protected function checkConnection(array $connection): Result
	{
		return (new MailboxConnector())->validateConnectionSnapshot($connection);
	}

	protected function prepareTargetSnapshot(ConnectionSnapshot $snapshot): Result
	{
		return $this->mailboxMigrationSwitcher->prepare($snapshot);
	}

	/**
	 * Without a generation this is the acceptance of a snapshot: the folders of the user missing on
	 * the target server are created there, except the ones the operation already failed to create.
	 *
	 * @param string[] $notCreatedSources
	 * @param bool $createMissing False when the operation already has its generation written.
	 * @param TargetFolderCreator|null $creator {@see acceptanceFolderCreator()}
	 */
	protected function prepareTargetSnapshotForMailbox(
		int $mailboxId,
		ConnectionSnapshot $snapshot,
		?int $generationId = null,
		array $notCreatedSources = [],
		bool $createMissing = true,
		?TargetFolderCreator $creator = null,
	): Result
	{
		return $this->mailboxMigrationSwitcher->prepareKnownTopology(
			$snapshot,
			$this->knownActiveDirectories($mailboxId, $generationId),
			null,
			$generationId === null,
			$notCreatedSources,
			$createMissing,
			$creator,
		);
	}

	/**
	 * Every folder the acceptance is about to create is written down before its CREATE, so that a check
	 * broken off by a lost answer or by a later folder does not lose its pair. The creation keeps to the share of one pass: what is left
	 * when it is spent is the hand over's {@see LateFolderReconciler}.
	 */
	private function acceptanceFolderCreator(int $mailboxId, string $operationId): TargetFolderCreator
	{
		return new TargetFolderCreator(
			$this->getAcceptanceCreationDeadline(),
			fn (string $source, string $target) => $this->acceptanceFolders->record($mailboxId, $operationId, $source, $target),
		);
	}

	protected function getAcceptanceCreationDeadline(): int
	{
		return time() + self::OPERATION_PASS_TIME_BUDGET_SECONDS;
	}

	/**
	 * @return string[]
	 */
	private static function notCreatedSourcesOf(?array $generation): array
	{
		$options = is_array($generation['OPTIONS'] ?? null) ? $generation['OPTIONS'] : [];

		return array_values(array_map(
			// Compared with the paths of the folders as the database reads them out, decoded
			static fn (array $folder): string => Emoji::decode((string)($folder['source'] ?? '')),
			array_filter((array)($options[self::STATE_FOLDER_NOT_CREATED] ?? []), 'is_array'),
		));
	}

	/**
	 * @return array<int, array{source: string, attempts: int, reason: string}>
	 */
	private static function folderCreateFailuresOf(?array $generation): array
	{
		$options = is_array($generation['OPTIONS'] ?? null) ? $generation['OPTIONS'] : [];
		$failures = [];
		foreach (array_filter((array)($options[self::STATE_FOLDER_CREATE_FAILURES] ?? []), 'is_array') as $failure)
		{
			$source = Emoji::decode((string)($failure['source'] ?? ''));
			if ($source === '')
			{
				continue;
			}

			$failures[] = [
				'source' => $source,
				'attempts' => max(0, (int)($failure['attempts'] ?? 0)),
				'reason' => (string)($failure['reason'] ?? ''),
			];
		}

		return $failures;
	}

	/**
	 * @param array<int, array{source: string, attempts: int, reason: string}> $failures
	 * @return array<int, array{source: string, attempts: int, reason: string}>
	 */
	private static function incrementFolderCreateFailure(array $failures, string $source, string $reason): array
	{
		foreach ($failures as &$failure)
		{
			if ($failure['source'] === $source)
			{
				$failure['attempts']++;
				$failure['reason'] = $reason;

				return $failures;
			}
		}
		unset($failure);

		$failures[] = [
			'source' => $source,
			'attempts' => 1,
			'reason' => $reason,
		];

		return $failures;
	}

	/**
	 * @param array<int, array{source: string, attempts: int, reason: string}> $failures
	 * @return array<int, array{source: string, attempts: int, reason: string}>
	 */
	private static function folderCreateFailureOptions(array $failures): array
	{
		return array_values(array_map(
			static fn (array $failure): array => [
				'source' => Emoji::encode($failure['source']),
				'attempts' => $failure['attempts'],
				'reason' => $failure['reason'],
			],
			$failures,
		));
	}

	/**
	 * The connected folders, their system roles and every ancestor needed to retain their topology.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function knownActiveDirectories(int $mailboxId, ?int $generationId = null): array
	{
		// Without a pointer the folders of a prepared generation are not a part of the source
		$scope = $generationId === null
			? GenerationScope::servingMailbox($mailboxId)
			: GenerationScope::exact($generationId);
		$rows = MailboxDirectoryTable::getList([
			'select' => [
				'ID', 'NAME', 'PATH', 'DELIMITER', 'LEVEL', 'PARENT_ID', 'ROOT_ID', 'IS_SYNC',
				'IS_DISABLED', 'IS_INCOME', 'IS_OUTCOME', 'IS_DRAFT', 'IS_TRASH', 'IS_SPAM',
			],
			'filter' => $scope->apply(['=MAILBOX_ID' => $mailboxId]),
			'order' => ['LEVEL' => 'ASC', 'ID' => 'ASC'],
		])->fetchAll();

		$byId = [];
		$required = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			$byId[$id] = $row;
			if (
				(int)$row['IS_SYNC'] === MailboxDirectoryTable::ACTIVE
				|| (int)$row['IS_INCOME'] === MailboxDirectoryTable::ACTIVE
				|| (int)$row['IS_OUTCOME'] === MailboxDirectoryTable::ACTIVE
				|| (int)$row['IS_DRAFT'] === MailboxDirectoryTable::ACTIVE
				|| (int)$row['IS_TRASH'] === MailboxDirectoryTable::ACTIVE
				|| (int)$row['IS_SPAM'] === MailboxDirectoryTable::ACTIVE
			)
			{
				$required[$id] = true;
			}
		}

		foreach (array_keys($required) as $id)
		{
			$parentId = (int)($byId[$id]['PARENT_ID'] ?? 0);
			while ($parentId > 0 && isset($byId[$parentId]) && !isset($required[$parentId]))
			{
				$required[$parentId] = true;
				$parentId = (int)($byId[$parentId]['PARENT_ID'] ?? 0);
			}
		}

		$directories = [];
		foreach ($rows as $row)
		{
			if (!isset($required[(int)$row['ID']]))
			{
				continue;
			}

			$flags = [];
			foreach ([
				'IS_OUTCOME' => '\\Sent',
				'IS_DRAFT' => '\\Drafts',
				'IS_TRASH' => '\\Trash',
				'IS_SPAM' => '\\Junk',
				'IS_DISABLED' => '\\Noselect',
			] as $field => $flag)
			{
				if ((int)$row[$field] === MailboxDirectoryTable::ACTIVE)
				{
					$flags[] = $flag;
				}
			}

			$parent = $byId[(int)($row['PARENT_ID'] ?? 0)] ?? null;
			$name = (string)$row['NAME'];
			if ($parent !== null)
			{
				$prefix = (string)$parent['PATH'] . (string)$row['DELIMITER'];
				if (str_starts_with((string)$row['PATH'], $prefix))
				{
					$name = substr((string)$row['PATH'], strlen($prefix));
				}
			}

			$directories[] = array_merge($row, ['FLAGS' => $flags, 'NAME' => $name]);
		}

		return $directories;
	}

	private function acceptSnapshotUnderLock(
		int $mailboxId,
		string $operationId,
		ConnectionSnapshot $snapshot,
		string $migratorService,
		array $imapDirectories,
		array $folderOutcomes,
		Result $result,
	): Result
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$lockedState = (new SettingsWriteCoordinator())->lockWithinTransaction($mailboxId, true, $operationId);
			if (!$lockedState->isSuccess())
			{
				return $this->rejectSnapshotAcceptance(
					$connection,
					$result,
					MigrationStage::Legacy,
					new Error('The mailbox is not found', self::ERROR_MAILBOX_NOT_FOUND),
				);
			}

			$state = $lockedState->getData();
			$mailbox = $state['mailbox'];
			if (!BackfillService::supportsServerType((string)$mailbox['SERVER_TYPE']))
			{
				return $this->rejectSnapshotAcceptance(
					$connection,
					$result,
					MigrationStage::Legacy,
					new Error(
						'The mailbox type does not support source migration',
						self::ERROR_MAILBOX_NOT_SUPPORTED,
					),
				);
			}

			$generation = $this->findGenerationInLockedState($state, $operationId);
			if ($generation === null)
			{
				$pending = $this->findPreparingGenerationInLockedState($state);
				if ($pending !== null)
				{
					return $this->rejectSnapshotAcceptance(
						$connection,
						$result,
						MigrationStage::PreparingG2,
						new Error(
							sprintf('The mailbox %u is already preparing another operation', $mailboxId),
							self::ERROR_ANOTHER_OPERATION,
						),
					);
				}

				$generation = $this->createGenerationFromSnapshot(
					$mailboxId,
					$operationId,
					$snapshot,
					$migratorService,
					$imapDirectories,
					$folderOutcomes,
					$result,
				);

				if ($generation === null)
				{
					$this->rollbackSnapshotAcceptance($connection);

					return $result->setData(['stage' => $this->getStage($mailboxId, $operationId)]);
				}

				$connection->commitTransaction();
				$this->acceptanceFolders->forget($mailboxId);

				return $result->setData(['stage' => $this->readStage($generation)]);
			}

			$stage = $this->readStage($generation);
			if (!$this->hasSameSnapshotIdentity($generation, $snapshot, $migratorService))
			{
				return $this->rejectSnapshotAcceptance(
					$connection,
					$result,
					$stage,
					new Error(
						'The accepted connection identity cannot be changed',
						self::ERROR_SNAPSHOT_IDENTITY_CONFLICT,
					),
				);
			}

			$this->fillServiceIdOfGeneration($mailboxId, $operationId, $generation, $mailbox, $snapshot, $stage);

			if ($stage->isFinal())
			{
				$connection->commitTransaction();

				return $result->setData(['stage' => $stage]);
			}

			$revision = (int)$generation['REVISION'];
			if ((string)$generation['STATUS'] !== MailboxSourceGenerationTable::STATUS_PREPARING)
			{
				return $this->rejectSnapshotAcceptance(
					$connection,
					$result,
					$stage,
					new Error(
						'The accepted generation has changed',
						self::ERROR_SNAPSHOT_REVISION_CONFLICT,
					),
				);
			}

			$storageFields = $snapshot->toStorageFields();
			if (
				(string)$generation['PASSWORD'] === (string)$storageFields['PASSWORD']
				&& ($generation['SMTP_PASSWORD'] ?? null) === $storageFields['SMTP_PASSWORD']
			)
			{
				$connection->commitTransaction();

				return $result->setData(['stage' => $stage]);
			}

			$updated = $this->updateSnapshotSecrets((int)$generation['ID'], [
				'PASSWORD' => $storageFields['PASSWORD'],
				'SMTP_PASSWORD' => $storageFields['SMTP_PASSWORD'],
				'REVISION' => $revision + 1,
			]);

			if (!$updated->isSuccess())
			{
				return $this->rejectSnapshotAcceptance(
					$connection,
					$result,
					$stage,
					new Error(
						MigrationMetrics::withoutSecretsOf(
							$storageFields,
							implode('; ', $updated->getErrorMessages()),
						),
						self::ERROR_SNAPSHOT_NOT_SAVED,
					),
				);
			}

			$stored = $this->readLockedGenerationById($connection, (int)$generation['ID']);
			if ((int)($stored['REVISION'] ?? -1) !== $revision + 1)
			{
				return $this->rejectSnapshotAcceptance(
					$connection,
					$result,
					$stage,
					new Error(
						'The accepted generation has changed',
						self::ERROR_SNAPSHOT_REVISION_CONFLICT,
					),
				);
			}

			$connection->commitTransaction();

			return $result->setData(['stage' => $stage]);
		}
		catch (\Throwable $exception)
		{
			$this->rollbackSnapshotAcceptance($connection);

			return $result
				->setData(['stage' => $this->safeStage($mailboxId, $operationId)])
				->addError(new Error(
					MigrationMetrics::withoutSecretsOf($snapshot->toStorageFields(), $exception->getMessage()),
					self::ERROR_SNAPSHOT_NOT_SAVED,
				))
			;
		}
	}

	protected function updateSnapshotSecrets(int $generationId, array $fields): UpdateResult
	{
		return MailboxSourceGenerationTable::update($generationId, $fields);
	}

	/**
	 * The same backfill for an operation whose switch has already happened.
	 *
	 * Such an acceptance is answered with the final stage and writes nothing else, so without this the
	 * number promised "by a later kit" would never arrive at a mailbox that moved before the row of the
	 * directory was installed - the one case the promise was made for. The write goes under the lock of
	 * the operation, the same one a pass of the migration takes, so no switch can run beside it.
	 *
	 * The identity is compared here as it is compared on the path of an unfinished operation: a kit of
	 * some other connection may not name the service of this mailbox.
	 */
	private function fillServiceIdOfFinishedOperation(
		int $mailboxId,
		string $operationId,
		ConnectionSnapshot $snapshot,
		string $migratorService,
		MigrationStage $stage,
	): void
	{
		$generation = $this->findGeneration($mailboxId, $operationId);
		if ($generation === null || !$this->hasSameSnapshotIdentity($generation, $snapshot, $migratorService))
		{
			return;
		}

		$mailbox = MailboxTable::getList([
			'select' => ['ID', 'SERVICE_ID', 'ACTIVE_GENERATION_ID'],
			'filter' => ['=ID' => $mailboxId],
			'limit' => 1,
		])->fetch();

		$this->fillServiceIdOfGeneration(
			$mailboxId,
			$operationId,
			$generation,
			$mailbox ?: [],
			$snapshot,
			$stage,
		);
	}

	/**
	 * Fills in the number of the mail service of a generation that was accepted without one.
	 *
	 * The other side names the service and may leave the number at zero: the directory of the services
	 * belongs to this module. A kit that arrives before the row of the directory is installed carries
	 * that zero, and a later kit of the same operation carries the resolved number - the connection
	 * itself is the same one, and it has been compared as such just above.
	 *
	 * The write itself belongs to {@see LateServiceBinder}, which a background walk runs over the
	 * mailboxes no repeated kit ever reaches: both sides of the number are written by one rule, and
	 * this call runs under the lock that rule expects of its caller. The rule finishes a binding it
	 * finds half written as well, so a kit coming again is the second way out of that state - the
	 * first being the walk.
	 *
	 * A refused write is not a refused kit - the mailbox works on the credentials it came with, and
	 * the next acceptance fills the number in - so the reason is journalled and the acceptance goes on.
	 */
	private function fillServiceIdOfGeneration(
		int $mailboxId,
		string $operationId,
		array $generation,
		array $mailbox,
		ConnectionSnapshot $snapshot,
		MigrationStage $stage,
	): void
	{
		$filled = $this->serviceBinder->fill(
			$mailboxId,
			(int)($snapshot->imap()['SERVICE_ID'] ?? 0),
			$generation,
			$mailbox,
		);

		if (!$filled->isSuccess())
		{
			MigrationMetrics::journalOperation(
				'service number not filled in',
				$mailboxId,
				$operationId,
				(int)($generation['ID'] ?? 0),
				$stage->value,
				implode('; ', $filled->getErrorMessages()),
			);
		}
	}

	private function safeStage(int $mailboxId, string $operationId): MigrationStage
	{
		try
		{
			return $this->getStage($mailboxId, $operationId);
		}
		catch (\Throwable)
		{
			return MigrationStage::Legacy;
		}
	}

	private function fetchMailboxServerType(int $mailboxId): ?string
	{
		$mailbox = MailboxTable::getList([
			'select' => ['SERVER_TYPE'],
			'filter' => ['=ID' => $mailboxId],
			'limit' => 1,
		])->fetch();

		return $mailbox === false ? null : (string)$mailbox['SERVER_TYPE'];
	}

	private function findGenerationInLockedState(array $state, string $operationId): ?array
	{
		foreach ($state['generations'] as $generation)
		{
			if ((string)$generation['OPERATION_ID'] === $operationId)
			{
				return $this->decodeLockedGeneration($generation);
			}
		}

		return null;
	}

	private function findPreparingGenerationInLockedState(array $state): ?array
	{
		foreach ($state['generations'] as $generation)
		{
			if (
				(string)$generation['STATUS'] === MailboxSourceGenerationTable::STATUS_PREPARING
				&& (string)$generation['OPERATION_ID'] !== BackfillService::OPERATION_ID
			)
			{
				return $this->decodeLockedGeneration($generation);
			}
		}

		return null;
	}

	private function decodeLockedGeneration(array $generation): array
	{
		foreach (['OPTIONS', 'PASSWORD', 'SMTP_PASSWORD'] as $field)
		{
			$generation[$field] = SettingsWriteCoordinator::decodeStoredField(
				MailboxSourceGenerationTable::class,
				$field,
				$generation[$field],
			);
		}

		return $generation;
	}

	private function readLockedGenerationById(Connection $connection, int $generationId): ?array
	{
		$locked = $connection->query(sprintf(
			'SELECT * FROM %s WHERE ID = %u FOR UPDATE',
			MailboxSourceGenerationTable::getTableName(),
			$generationId,
		))->fetch();

		return $locked ? $this->decodeLockedGeneration($locked) : null;
	}

	private function rejectSnapshotAcceptance(
		Connection $connection,
		Result $result,
		MigrationStage $stage,
		Error $error,
	): Result
	{
		$this->rollbackSnapshotAcceptance($connection);

		return $result->setData(['stage' => $stage])->addError($error);
	}

	private function rollbackSnapshotAcceptance(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (\Throwable)
		{
		}
	}

	private function hasSameSnapshotIdentity(
		array $generation,
		ConnectionSnapshot $snapshot,
		string $migratorService,
	): bool
	{
		if (!ConnectionSnapshot::isCompleteStorage($generation))
		{
			return false;
		}

		$stored = ConnectionSnapshot::fromStorage($generation)->toStorageFields();
		$accepted = $snapshot->toStorageFields();
		/*
			The passwords are left out because they are the one thing a repeated kit is expected to
			bring anew, and the number of the mail service because it is no part of the connection at
			all: the identity of a source is its address, its host, its port and its login, while the
			number is a reference into the directory of this module. The other side names the service
			by name and leaves the number at zero for this module to resolve, so the same mailbox
			arrives with a zero before the row of the directory exists and with a number afterwards.
			Counted as identity, that alone would refuse every later kit of the operation.
		*/
		unset($stored['PASSWORD'], $stored['SMTP_PASSWORD'], $stored['SERVICE_ID']);
		unset($accepted['PASSWORD'], $accepted['SMTP_PASSWORD'], $accepted['SERVICE_ID']);

		foreach ($accepted as $field => $value)
		{
			if (($stored[$field] ?? null) !== $value)
			{
				return false;
			}
		}

		$options = is_array($generation['OPTIONS'] ?? null) ? $generation['OPTIONS'] : [];
		$storedMigratorService = trim((string)($options[MailboxSourceGenerationTable::OPTION_MIGRATOR_SERVICE] ?? ''));

		return $storedMigratorService === $migratorService;
	}

	private function createGenerationFromSnapshot(
		int $mailboxId,
		string $operationId,
		ConnectionSnapshot $snapshot,
		string $migratorService,
		array $imapDirectories,
		array $folderOutcomes,
		Result $result,
	): ?array
	{
		$options = [self::STATE_STAGE => MigrationStage::PreparingG2->value] + self::folderOutcomeOptions($folderOutcomes);

		if ($migratorService !== '')
		{
			$options[MailboxSourceGenerationTable::OPTION_MIGRATOR_SERVICE] = $migratorService;
		}

		$storageFields = $snapshot->toStorageFields();
		$added = MailboxSourceGenerationTable::add($storageFields + [
			'MAILBOX_ID' => $mailboxId,
			'OPERATION_ID' => $operationId,
			'STATUS' => MailboxSourceGenerationTable::STATUS_PREPARING,
			'REVISION' => 0,
			'OPTIONS' => $options,
			'DATE_CREATE' => new DateTime(),
		]);

		if (!$added->isSuccess())
		{
			$result->addError(new Error(
				sprintf(
					'The generation of the operation %s is not created: %s',
					$operationId,
					MigrationMetrics::withoutSecretsOf($storageFields, implode('; ', $added->getErrorMessages())),
				),
				self::ERROR_GENERATION_NOT_CREATED,
			));

			return null;
		}

		/*
			From this row on the mailbox prepares one generation more, and the scope of its folders is
			derived from that answer - which is cached for the process. Dropping it here is what the
			switch does at the other end of the migration {@see Switcher::invalidateGenerationCaches()};
			the call takes the answer about the prepared generations along with the folder trees. No
			read of the folders of this mailbox later in the same process is reachable today, so this
			is the invariant being kept rather than a defect being fixed.
		*/
		\Bitrix\Mail\MailboxDirectory::invalidateCache($mailboxId);

		$generation = $this->findGeneration($mailboxId, $operationId);
		if ($generation === null)
		{
			$result->addError(new Error(
				sprintf('The generation of the operation %s cannot be read after creation', $operationId),
				self::ERROR_GENERATION_NOT_CREATED,
			));
		}

		if ($generation !== null)
		{
			$generationId = (int)$generation['ID'];
			$this->storeAcceptedDirectories($mailboxId, $generationId, $imapDirectories);
			if ($this->findAmbiguousRolesInScope($mailboxId, GenerationScope::exact($generationId)) !== [])
			{
				$result->addError(new Error(
					'The accepted IMAP directory roles cannot be saved',
					self::ERROR_SNAPSHOT_NOT_SAVED,
				));

				return null;
			}

			foreach ((array)($folderOutcomes['notCreated'] ?? []) as $folder)
			{
				MigrationMetrics::journalOperation(
					'folder_not_created',
					$mailboxId,
					$operationId,
					$generationId,
					MigrationStage::PreparingG2->value,
					sprintf('%s (directory %u)', (string)$folder['reason'], (int)$folder['directoryId']),
				);
			}
		}

		return $generation;
	}

	/**
	 * The state keys of the folders the acceptance brought into the target server or failed to; none
	 * at all when every folder had a pair already.
	 *
	 * The paths are kept with their emoji encoded, as the folders of the database keep them: the state
	 * is a serialized text, and a table in a charset without four-byte characters would not take it.
	 *
	 * @param array{pairs?: array, notCreated?: array} $folderOutcomes
	 */
	private static function folderOutcomeOptions(array $folderOutcomes): array
	{
		$options = [];

		$pairs = array_values(array_map(
			static fn (array $pair): array => [
				'source' => Emoji::encode((string)$pair['source']),
				'target' => Emoji::encode((string)$pair['target']),
				'created' => (bool)$pair['created'],
			],
			(array)($folderOutcomes['pairs'] ?? []),
		));
		if ($pairs !== [])
		{
			$options[self::STATE_FOLDER_PAIRS] = $pairs;
		}

		$notCreated = array_values(array_map(
			static fn (array $folder): array => [
				'source' => Emoji::encode((string)$folder['source']),
				'reason' => (string)$folder['reason'],
			],
			(array)($folderOutcomes['notCreated'] ?? []),
		));
		if ($notCreated !== [])
		{
			$options[self::STATE_FOLDER_NOT_CREATED] = $notCreated;
		}

		return $options;
	}

	private function storeAcceptedDirectories(int $mailboxId, int $generationId, array $directories): void
	{
		(new MailboxDirectoryHelper(
			$mailboxId,
			null,
			GenerationScope::exact($generationId),
		))->syncDbDirsSnapshot($directories);
	}

	/**
	 * @return array<string, mixed> The stored credentials of the generation.
	 */
	private function extractConnection(array $generation): array
	{
		$connection = [];
		foreach (Switcher::PROJECTED_FIELDS as $field)
		{
			$connection[$field] = $generation[$field] ?? null;
		}

		return $connection;
	}

	private function readStage(array $generation): MigrationStage
	{
		if ((string)$generation['STATUS'] === MailboxSourceGenerationTable::STATUS_ACTIVE)
		{
			return MigrationStage::Switched;
		}

		$stored = (string)($generation['OPTIONS'][self::STATE_STAGE] ?? '');
		$stage = MigrationStage::tryFrom($stored) ?? MigrationStage::PreparingG2;

		if ($stage === MigrationStage::ReadyToSwitch && !$this->isSwitchAuthorized($generation))
		{
			return MigrationStage::AwaitingSwitchAuthorization;
		}

		return $stage;
	}

	private function isSwitchAuthorized(array $generation): bool
	{
		$options = is_array($generation['OPTIONS'] ?? null) ? $generation['OPTIONS'] : [];

		return ($options[MailboxSourceGenerationTable::OPTION_FINAL_SWITCH_AUTHORIZED] ?? null) === 'Y';
	}

	private function isCancellationRequested(array $generation): bool
	{
		$options = is_array($generation['OPTIONS'] ?? null) ? $generation['OPTIONS'] : [];

		return ($options[MailboxSourceGenerationTable::OPTION_CANCEL_REQUESTED] ?? null) === 'Y';
	}

	/**
	 * What the operation has written down about itself, as one value to compare a pass against
	 * {@see run()}. Everything a stage keeps for its next pass lives in the options of the
	 * generation, so almost every stage is answered for by this alone.
	 *
	 * Almost: a stage that deletes rows and needs no bookmark to continue leaves nothing here, and
	 * such a stage reports its own progress instead of being read from these options
	 * {@see advance()}.
	 */
	private function progressFingerprint(int $mailboxId, string $operationId): string
	{
		$generation = $this->findGeneration($mailboxId, $operationId);
		if ($generation === null)
		{
			return '';
		}

		$options = is_array($generation['OPTIONS'] ?? null) ? $generation['OPTIONS'] : [];
		unset(
			$options['metrics'],
			$options[self::STATE_ERROR],
			$options[MailboxSourceGenerationTable::OPTION_FINAL_SWITCH_AUTHORIZED],
			$options[MailboxSourceGenerationTable::OPTION_CANCEL_REQUESTED],
		);

		return hash('sha256', serialize([
			(string)$generation['STATUS'],
			$options,
		]));
	}

	/**
	 * Stores the stage and the progress of the operation with its generation, so the next
	 * run of the same operation continues from here.
	 *
	 * @param array<string, mixed> $progress
	 */
	private function store(int $generationId, ?MigrationStage $stage, array $progress = []): MigrationStage
	{
		$generation = $this->findGenerationById($generationId) ?? [
			'STATUS' => MailboxSourceGenerationTable::STATUS_PREPARING,
			'OPTIONS' => [],
		];

		$options = is_array($generation['OPTIONS']) ? $generation['OPTIONS'] : [];

		if ($stage !== null)
		{
			$options[self::STATE_STAGE] = $stage->value;
			unset($options[self::STATE_ERROR], $options[self::STATE_MISSING_TARGET_ROLE]);
		}

		MailboxSourceGenerationTable::update($generationId, ['OPTIONS' => $progress + $options]);

		return $stage ?? $this->readStage($generation);
	}

	/**
	 * Keeps the stage and the reason of the failure and reports both: the generation
	 * stays PREPARING and the operation is continued by the next run. The failure also
	 * reaches the journal and the counters of the pass, addressed by the operation and
	 * the generation it happened in.
	 */
	private function stop(
		MigrationMetrics $metrics,
		int $generationId,
		MigrationStage $stage,
		Result $result,
		Error ...$errors,
	): Result
	{
		$code = $errors === [] ? '' : (string)$errors[0]->getCode();

		$generation = $this->findGenerationById($generationId);
		$options = is_array($generation['OPTIONS'] ?? null) ? $generation['OPTIONS'] : [];
		$options[self::STATE_STAGE] = $stage->value;
		$options[self::STATE_ERROR] = $code;
		$customData = $errors === [] ? null : $errors[0]->getCustomData();
		$missingRole = is_array($customData) ? ($customData['missingRole'] ?? null) : null;
		if (is_string($missingRole))
		{
			$options[self::STATE_MISSING_TARGET_ROLE] = $missingRole;
		}
		else
		{
			unset($options[self::STATE_MISSING_TARGET_ROLE]);
		}

		MailboxSourceGenerationTable::update($generationId, ['OPTIONS' => $options]);

		foreach ($errors as $error)
		{
			$sanitized = $metrics->sanitize($error);

			$result->addError($sanitized);
			$metrics->journalError($sanitized);
		}

		$metrics->countFailure();
		$metrics->save();

		return $result->setData(['stage' => $stage]);
	}

	private function findGeneration(int $mailboxId, string $operationId): ?array
	{
		if ($mailboxId <= 0 || $operationId === '')
		{
			return null;
		}

		$row = MailboxSourceGenerationTable::getList([
			'select' => ['ID', 'OPERATION_ID', 'STATUS', 'REVISION', 'OPTIONS', ...self::CONNECTION_FIELDS],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=OPERATION_ID' => $operationId,
			],
		])->fetch();

		return $row ?: null;
	}

	private function findGenerationById(int $generationId): ?array
	{
		$row = MailboxSourceGenerationTable::getList([
			'select' => ['ID', 'OPERATION_ID', 'STATUS', 'REVISION', 'OPTIONS'],
			'filter' => ['=ID' => $generationId],
		])->fetch();

		return $row ?: null;
	}

	/**
	 * Whether the pass must stop where it stands. The one question the points inside a pass
	 * ask - every folder and every batch of letters - and the one place its reasons are named.
	 *
	 * Two of them, and they are alike in everything but the storage they live in:
	 *
	 * - the generation the pass imports into is gone. That is how the deletion of a mailbox asks
	 *   {@see MailboxDeletion::takeMailboxOver()}: the rows a pass writes belong to a mailbox
	 *   nobody is going to sweep them for anymore, and a repeated run finds no mailbox at all;
	 * - a switch of the rollout is on {@see RolloutBrakeReader}. The operator presses it while
	 *   the pass is already running, so it is read past the option cache of the process - the
	 *   cached value is the one taken before the press - and bounded by a window of time rather
	 *   than asked at every point, because the points are as many as the folders and the batches.
	 *
	 * The third reason - the operator leading the transfer by hand - is not asked here and is
	 * not meant to be: it holds the transfer at the boundary of a stage {@see ManualMigrationControl},
	 * which is a question about the next stage and not about the middle of the current one.
	 *
	 * Whatever the reason, the pass behaves as it does when a budget runs out: it finishes the
	 * small step it is on, keeps its cursors and reports itself unfinished. Nothing of the
	 * operation is rewritten - not the stage, not the pointer of the active generation, not the
	 * status of the generation and not the stored authorization of the switch.
	 */
	private function mustStopThePass(int $generationId): bool
	{
		return $this->brakes->findRefusal() !== null || $this->findGenerationById($generationId) === null;
	}
}
