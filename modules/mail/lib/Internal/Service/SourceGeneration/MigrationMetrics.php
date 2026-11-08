<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internal\Service\SourceGeneration\Matching\MatchDecision;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\Internals\SourceGenerationMatchTable;
use Bitrix\Main\Application;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The observable record of one source generation operation: its counters and its
 * journal entries, neither of which ever carries a connection secret.
 *
 * The counters live in the OPTIONS of the generation they describe, next to the stage
 * and the last error of the same operation: they share its lifetime, they are removed
 * with it and they need no storage of their own. Three scopes keep the shadow pass, the
 * real import and the cleanup apart, so the same letter counted twice by two passes is
 * still readable as two passes:
 *
 *   OPTIONS['metrics']['shadow' | 'import' | 'cleanup'][counter] => int
 *
 * A counted event accumulates over the runs of the operation ({@see save()} adds what
 * this instance has counted), while a measured value replaces the stored one.
 */
final class MigrationMetrics
{
	/** The dry pass: what the matching would decide, with nothing written */
	public const SCOPE_SHADOW = 'shadow';

	/** The real import of the prepared generation */
	public const SCOPE_IMPORT = 'import';

	/** Removal of the physical data of a generation */
	public const SCOPE_CLEANUP = 'cleanup';

	/** The tail of the transfer: the letters the module puts onto the new source itself */
	public const SCOPE_TAIL = 'tail';

	/** The catching up pass: what the user did to their letters while the transfer ran */
	public const SCOPE_REPLAY = 'replay';

	public const PROCESSED = 'processed';
	public const MATCHED = 'matched';
	public const NEW = 'new';
	public const AMBIGUOUS = 'ambiguous';
	public const FAILED = 'failed';
	public const RETRIES = 'retries';
	public const BATCHES = 'batches';
	public const BATCH_TIME = 'batch_ms';
	public const CLEANUP_ERRORS = 'cleanup_errors';

	/* Counters of the tail append stage */

	/** Letters the new source took and named the coordinates of */
	public const APPENDED = 'appended';

	/**
	 * Letters the new source took while naming no coordinates for them, so they went up
	 * carrying the one time mark of the fallback recognition instead.
	 */
	public const MARKED = 'marked';

	/**
	 * Letters the new source would not take: a pass ends with such a letter still current, and
	 * the counter is how a source refusing the remainder is read off the operation.
	 */
	public const REFUSED = 'refused';

	/**
	 * Letters that are on the new source without a verdict of ours, because the server named
	 * no coordinates for them. Such a letter is read back by the ordinary route of the
	 * matching instead of by the stored result.
	 */
	public const UNRECOGNIZED = 'unrecognized';

	/**
	 * Letters the switch left in the archived generation alone: the window of the append stage
	 * ran out before they could be put onto the new source, so the mailbox was handed over
	 * without them.
	 *
	 * Counted apart from every other outcome on purpose - this is the one counter that names a
	 * loss. Such a letter is gone from the mail, from the search and from the thread after the
	 * switch, and its attachments cannot be fetched anymore. Which letters they were is in the
	 * log of the portal, one entry each.
	 */
	public const LEFT_BEHIND = 'left_behind';

	/**
	 * Letters of the remainder the append stage set aside: the new source did not take them in
	 * the attempts the module gives one message, so the walk went on over the rest of the
	 * remainder without them ({@see \Bitrix\Mail\Helper\Mailbox\HistorySyncAttemptService}).
	 *
	 * The loss is the same as of a letter left behind by the switch, and the reason is not: this
	 * one is about the letter itself - above the size limit of the server, a file of it gone -
	 * and it was paid so that the hundreds of letters behind it would reach the new source. The
	 * two are counted apart because that difference is what somebody reads the operation for,
	 * and the log of the portal names the letters of both, one entry each.
	 */
	public const SET_ASIDE = 'set_aside';

	/**
	 * Files an appended letter should have carried and does not have: the old source did not
	 * hand them over, our own storage did not keep them, or the row of the file outlived the
	 * file itself. Such a letter is not appended at all - the counter names what holds it.
	 */
	public const MISSING_FILES = 'missing_files';

	/**
	 * Letters our own row held as an envelope alone - neither the text nor the markup of them -
	 * whose body was fetched back off the source still serving the mailbox, while it still
	 * could be ({@see TailBodyFetcher}).
	 */
	public const BODY_RESTORED = 'body_restored';

	/**
	 * Letters that went onto the new source as an envelope: our own row held no body of them
	 * and the old source handed none over either.
	 *
	 * Such a letter is appended all the same and counted among the appended ones, because an
	 * envelope on the new source is what keeps it in the list, in the search and in the thread
	 * of the switched mailbox. This counter is what tells the two apart: a letter the user
	 * opens and finds empty was not carried over whole, and after the switch its body is
	 * beyond reach.
	 */
	public const ENVELOPE_ONLY = 'envelope_only';

	/* Counters of the action replay pass */

	/** Letters the pass looked at: the ones with a live placement on both sides */
	public const PAIRS = 'pairs';

	/** Move commands the pass handed to the new source and the row of which it repainted */
	public const MOVED = 'moved';

	/**
	 * Letters of a move command the new source would not carry out. One command carries every
	 * letter travelling between one pair of folders, so a refusal of it is counted for each of
	 * them. Nothing is rolled back and no repeat is scheduled, which makes this counter the whole
	 * record of what did not reach the server.
	 *
	 * A source that cannot be reached at all is not among them: the pass refuses as a whole and
	 * holds the switch back instead of counting the mailbox refused letter by letter.
	 */
	public const MOVE_REFUSED = 'move_refused';

	/** Read states written as an intention for the next synchronization of the folder to push */
	public const READ_INTENTS = 'read_intents';

	/**
	 * Readings that did not make it over: the new source turned the flag command down, the letter
	 * was moved anyway, and the reading of it is lost. Not a letter held back - the move is the
	 * promise the transfer is made of and the reading is the smaller thing - and not a delivery
	 * postponed either: the move leaves the row with no number, the synchronization of the new
	 * folder brings the letter in as a row of its own carrying the flags the server holds, and
	 * nothing afterwards pushes the reading the row was still waiting to. So the letter is where
	 * the portal wants it and reads on the new source the way the new source had it, and this is
	 * the count of the letters that happened to.
	 */
	public const FLAG_REFUSED = 'flag_refused';

	/**
	 * Letters whose role the prepared generation keeps no folder of - a new source without a
	 * spam folder, say. Such a letter is left where it is; the counter is what says so.
	 */
	public const UNROUTED = 'unrouted';

	/** Gauges: the last measurement wins over the stored one */
	public const BATCH_TIME_MAX = 'batch_ms_max';
	public const GENERATION_ROWS = 'generation_rows';

	private const STORAGE_KEY = 'metrics';

	/** How a measured value joins the one already stored */
	private const MERGE_ADD = 'add';
	private const MERGE_REPLACE = 'replace';
	private const MERGE_MAX = 'max';

	private const LOGGER_ID = 'mail.sourceGeneration.migration';

	private const SECRET_MASK = '***';

	/**
	 * Everything whose value never reaches a journal entry, matched by a part of the key.
	 *
	 * The login is one of them: it is the half of the credentials of the mailbox that a
	 * rejecting server likes to quote back, and a journal is read by more people than the
	 * mailbox itself. The operation stays addressable without it - every entry carries the
	 * mailbox, the generation and the operation. The address of the server is deliberately
	 * left readable: it names which host refused, and it is no credential.
	 */
	private const SECRET_KEY_PARTS = ['PASSWORD', 'OAUTH', 'TOKEN', 'SECRET', 'LOGIN'];

	/** @var array<string, int> */
	private array $measured = [];

	/** @var array<string, string> Counter => how it joins the stored value */
	private array $merge = [];

	private ?float $batchStartedAt = null;

	/** @var string[] Values replaced by the mask everywhere in a journal entry */
	private array $secrets = [];

	public function __construct(
		private readonly int $mailboxId,
		private readonly int $generationId,
		private readonly string $operationId,
		private readonly string $scope = self::SCOPE_IMPORT,
		private ?LoggerInterface $logger = null,
	)
	{
	}

	/**
	 * The stored counters of a generation, by scope.
	 *
	 * @return array<string, array<string, int>>
	 */
	public static function read(int $generationId): array
	{
		$row = MailboxSourceGenerationTable::getList([
			'select' => ['OPTIONS'],
			'filter' => ['=ID' => $generationId],
		])->fetch();

		$stored = is_array($row['OPTIONS'] ?? null) ? ($row['OPTIONS'][self::STORAGE_KEY] ?? []) : [];

		return is_array($stored) ? $stored : [];
	}

	/**
	 * @return array<string, int>
	 */
	public static function readScope(int $generationId, string $scope): array
	{
		$scoped = self::read($generationId)[$scope] ?? [];

		return is_array($scoped) ? $scoped : [];
	}

	/**
	 * Keeps the values of a credential set out of every journal entry of this instance.
	 * Called with the connection snapshot of the generation before anything is journaled.
	 */
	public function hideSecretsOf(array $connection): void
	{
		$this->secrets = array_merge($this->secrets, self::secretValuesOf($connection));
	}

	/**
	 * The same masking for a text built where no metrics instance exists yet: the message of
	 * a refused write is assembled from the errors of a row whose fields are the credentials
	 * themselves, and a generation is created before its operation has anything to count.
	 */
	public static function withoutSecretsOf(array $credentials, string $message): string
	{
		foreach (self::secretValuesOf($credentials) as $secret)
		{
			$message = str_replace($secret, self::SECRET_MASK, $message);
		}

		return $message;
	}

	/**
	 * Counts an event of the pass: the stored counter grows by the same amount.
	 */
	public function add(string $counter, int $delta = 1): void
	{
		$this->measured[$counter] = ($this->measured[$counter] ?? 0) + $delta;
		$this->merge[$counter] ??= self::MERGE_ADD;
	}

	/**
	 * Reports a measurement of the pass: it replaces the stored one instead of joining it.
	 */
	public function set(string $gauge, int $value): void
	{
		$this->measured[$gauge] = $value;
		$this->merge[$gauge] = self::MERGE_REPLACE;
	}

	/**
	 * One decided letter: the processed counter and the counter of its outcome.
	 */
	public function countDecision(MatchDecision $decision): void
	{
		$this->add(self::PROCESSED);
		$this->add(self::counterOfState($decision->state));
	}

	public function countFailure(): void
	{
		$this->add(self::FAILED);
	}

	public function countCleanupError(): void
	{
		$this->add(self::CLEANUP_ERRORS);
	}

	public function startBatch(): void
	{
		$this->batchStartedAt = microtime(true);
	}

	/**
	 * Closes the batch opened by {@see startBatch()}: its duration joins the total and
	 * competes for the slowest one.
	 */
	public function finishBatch(): void
	{
		if ($this->batchStartedAt === null)
		{
			return;
		}

		$elapsed = (int)round((microtime(true) - $this->batchStartedAt) * 1000);
		$this->batchStartedAt = null;

		$this->add(self::BATCHES);
		$this->add(self::BATCH_TIME, $elapsed);

		$this->measured[self::BATCH_TIME_MAX] = max($this->measured[self::BATCH_TIME_MAX] ?? 0, $elapsed);
		$this->merge[self::BATCH_TIME_MAX] = self::MERGE_MAX;
	}

	/**
	 * The physical rows the generation holds right now, over all generation scoped tables.
	 */
	public function measureGenerationRows(): void
	{
		if ($this->generationId <= 0)
		{
			return;
		}

		$connection = Application::getConnection();
		$rows = 0;

		foreach (array_keys(BackfillService::GENERATION_SCOPED_TABLES) as $table)
		{
			$rows += (int)$connection->queryScalar(sprintf(
				'SELECT COUNT(*) FROM %s WHERE MAILBOX_ID = %u AND GENERATION_ID = %u',
				$table,
				$this->mailboxId,
				$this->generationId,
			));
		}

		$this->set(self::GENERATION_ROWS, $rows);
	}

	/**
	 * The outcomes the real import has already written down, taken from the matching
	 * results of the generation: the import decides inside the sync engine, so the
	 * distribution is read from what it stored instead of being counted twice. The
	 * attempts of a stored result are the repeats of that letter.
	 */
	public function measureImportedResults(): void
	{
		if ($this->generationId <= 0)
		{
			return;
		}

		/*
			The mailbox is named next to the generation although the generation alone identifies
			the rows: the journal is indexed by (MAILBOX_ID, GENERATION_ID, STATE, ATTEMPTS), so
			with the mailbox in the condition the distribution is answered by the index alone
			instead of a temporary table over every row of the generation.
		*/
		$rows = Application::getConnection()->query(sprintf(
			'SELECT STATE, COUNT(*) AS CNT, SUM(ATTEMPTS) AS ATTEMPTS FROM %s'
			. ' WHERE MAILBOX_ID = %u AND GENERATION_ID = %u GROUP BY STATE',
			SourceGenerationMatchTable::getTableName(),
			$this->mailboxId,
			$this->generationId,
		))->fetchAll();

		$processed = 0;
		$retries = 0;

		foreach ($rows as $row)
		{
			$count = (int)$row['CNT'];
			$processed += $count;
			$retries += max((int)$row['ATTEMPTS'] - $count, 0);

			$this->set(self::counterOfState((string)$row['STATE']), $count);
		}

		$this->set(self::PROCESSED, $processed);
		$this->set(self::RETRIES, $retries);
	}

	/**
	 * Stores what this instance has measured and starts over: counters are added to the
	 * stored ones, gauges replace them.
	 */
	public function save(): void
	{
		if ($this->measured === [] || $this->generationId <= 0)
		{
			return;
		}

		$row = MailboxSourceGenerationTable::getList([
			'select' => ['ID', 'OPTIONS'],
			'filter' => ['=ID' => $this->generationId],
		])->fetch();

		if (!$row)
		{
			return;
		}

		$options = is_array($row['OPTIONS']) ? $row['OPTIONS'] : [];
		$metrics = is_array($options[self::STORAGE_KEY] ?? null) ? $options[self::STORAGE_KEY] : [];
		$scoped = is_array($metrics[$this->scope] ?? null) ? $metrics[$this->scope] : [];

		foreach ($this->measured as $counter => $value)
		{
			$stored = (int)($scoped[$counter] ?? 0);

			$scoped[$counter] = match ($this->merge[$counter] ?? self::MERGE_ADD)
			{
				self::MERGE_REPLACE => $value,
				self::MERGE_MAX => max($stored, $value),
				default => $stored + $value,
			};
		}

		$metrics[$this->scope] = $scoped;
		$options[self::STORAGE_KEY] = $metrics;

		MailboxSourceGenerationTable::update($this->generationId, ['OPTIONS' => $options]);

		$this->measured = [];
		$this->merge = [];
	}

	/**
	 * A failure of the operation, addressable by the operation and the generation it
	 * happened in. The message is stripped of the credentials of the source first: a
	 * journal is read by more people than the mailbox itself.
	 */
	public function journalError(Error $error): void
	{
		/*
			The identifiers are placeholders of the message and not context alone: a
			formatter interpolates the template it is given and drops the rest, so an
			entry addressed by its context only would reach the journal without them.
		*/
		$this->getLogger()->error(
			'Source generation migration failed: {reason}'
			. ' (code {code}, {scope} pass, operation {operationId}, generation {generationId}, mailbox {mailboxId})',
			[
				'reason' => $this->redact((string)$error->getMessage()),
				'code' => (string)$error->getCode(),
				'scope' => $this->scope,
				'mailboxId' => $this->mailboxId,
				'generationId' => $this->generationId,
				'operationId' => $this->operationId,
			],
		);
	}

	public static function journalOperation(
		string $event,
		int $mailboxId,
		string $operationId,
		int $generationId = 0,
		?string $stage = null,
		?string $errorCode = null,
	): void
	{
		$logger = (new LoggerFactory())->createById(self::LOGGER_ID, [], false) ?? new NullLogger();
		$logger->info(
			'Migration operation {event} (operation {operationId}, generation {generationId},'
			. ' mailbox {mailboxId}, stage {stage}, error {errorCode})',
			[
				'event' => $event,
				'operationId' => $operationId,
				'generationId' => $generationId,
				'mailboxId' => $mailboxId,
				'stage' => $stage ?? '-',
				'errorCode' => $errorCode ?? '-',
			],
		);
	}

	/**
	 * The same error with the credentials of the source masked out of its message. A
	 * server may answer with the command it rejected, credentials included, and that
	 * answer travels as the reason of the failure.
	 */
	public function sanitize(Error $error): Error
	{
		$message = (string)$error->getMessage();
		$masked = $this->redact($message);

		return $masked === $message
			? $error
			: new Error($masked, $error->getCode(), $error->getCustomData())
		;
	}

	/**
	 * The counter of a matching state, e.g. MATCHED => matched.
	 */
	public static function counterOfState(string $state): string
	{
		return match ($state)
		{
			SourceGenerationMatchTable::STATE_MATCHED => self::MATCHED,
			SourceGenerationMatchTable::STATE_AMBIGUOUS => self::AMBIGUOUS,
			default => self::NEW,
		};
	}

	/**
	 * @return string[] Values of a credential set whose key names a secret.
	 */
	private static function secretValuesOf(array $credentials): array
	{
		$secrets = [];

		foreach ($credentials as $key => $value)
		{
			if (is_string($value) && $value !== '' && self::isSecretKey((string)$key))
			{
				$secrets[] = $value;
			}
		}

		return $secrets;
	}

	private static function isSecretKey(string $key): bool
	{
		foreach (self::SECRET_KEY_PARTS as $part)
		{
			if (mb_stripos($key, $part) !== false)
			{
				return true;
			}
		}

		return false;
	}

	private function redact(string $message): string
	{
		foreach ($this->secrets as $secret)
		{
			$message = str_replace($secret, self::SECRET_MASK, $message);
		}

		return $message;
	}

	/**
	 * The registry check is skipped on purpose, as elsewhere in the module: a failed
	 * migration of a mailbox is an incident, not diagnostics someone switches on first.
	 */
	private function getLogger(): LoggerInterface
	{
		$this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID, [], false) ?? new NullLogger();

		return $this->logger;
	}
}
