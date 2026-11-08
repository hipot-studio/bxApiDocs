<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Closure;
use Throwable;

/**
 * The two switches of the rollout - the emergency brake and the feature switch - as they stand
 * right now, read past the option cache of the process.
 *
 * The cache is why this exists. The core loads the options of a module into memory once per
 * process, and a pass of the agent lives long after that: the value it reads the usual way is the
 * one taken before the operator pressed anything. For the admission of a mailbox that is enough -
 * it happens at the entry of the pass - but not for a question asked in the middle of one, and
 * least of all for the question asked in front of the switch, which is irreversible.
 *
 * The same trick and for the same reason lives in {@see \Bitrix\Mail\Update\LateServiceBindingStepper}.
 *
 * The price is bounded by the freshness window and not by the number of steps of the pass, so a
 * mailbox of a hundred folders costs what a mailbox of one folder costs. Within a pass of three
 * seconds {@see MigrationService::OPERATION_PASS_TIME_BUDGET_SECONDS} that is a handful of reads
 * of one row by an index, against the hundreds of queries and the network traffic with the mail
 * server the same pass spends anyway. The point of no return is served by {@see findRefusalNow()}
 * instead, which is not bounded at all: one query against an irreversible hand-over.
 *
 * The conditions and their order are the ones of {@see BackfillService::findMigrationRefusal()} -
 * this is a second way to read the same two switches, never a second rule about them.
 */
class RolloutBrakeReader
{
	public const REFUSAL_MAILHOST_STOPPED = 'MAILHOST_MIGRATION_STOPPED';
	public const SOURCE_MANUAL = 'manual';
	public const SOURCE_MAILHOST = 'mailhost';
	public const SOURCE_UNKNOWN = 'unknown';
	public const SOURCE_NONE = 'none';

	/**
	 * How long the answer of one read is served before the storage is asked again. Measured
	 * from the read itself and not by the clock of the portal: a window counted in whole
	 * seconds would be a whole second long or no time at all, depending on when the pass
	 * happened to ask.
	 */
	public const FRESHNESS_WINDOW_SECONDS = 1.0;

	private ?string $refusal = null;
	private float $readAt = 0.0;
	private ?string $mailhostErrorCode = null;
	private ?int $mailhostRevision = null;
	private string $source = self::SOURCE_UNKNOWN;

	public function __construct(
		private readonly ?Closure $optionsReader = null,
		private readonly ?Closure $mailhostSnapshotReader = null,
	)
	{
	}

	/**
	 * Which switch of the rollout stops the pass right now, if one does, with the answer of the
	 * last read reused while it is fresh.
	 *
	 * @return string|null One of the BackfillService::REFUSAL_* names, null while nothing stops it.
	 */
	public function findRefusal(): ?string
	{
		if ($this->readAt > 0.0 && microtime(true) - $this->readAt < self::FRESHNESS_WINDOW_SECONDS)
		{
			return $this->refusal;
		}

		return $this->findRefusalNow();
	}

	/**
	 * The same question asked of the storage in any case - for the callers that may not be
	 * answered by a value of a second ago.
	 */
	public function findRefusalNow(): ?string
	{
		$values = $this->readStoredOptions();
		$snapshot = $this->readMailhostSnapshot();
		$this->mailhostErrorCode = $snapshot['lastErrorCode'];
		$this->mailhostRevision = $snapshot['revision'];
		$this->source = match (true)
		{
			$this->valueOf($values, BackfillService::MIGRATION_STOP_OPTION) === 'Y' => self::SOURCE_MANUAL,
			$snapshot['known'] && $snapshot['stopped'] === true => self::SOURCE_MAILHOST,
			$snapshot['known'] => self::SOURCE_NONE,
			default => self::SOURCE_UNKNOWN,
		};

		$this->refusal = match (true)
		{
			$this->valueOf($values, BackfillService::MIGRATION_STOP_OPTION) === 'Y'
				=> BackfillService::REFUSAL_MIGRATION_STOPPED,
			$snapshot['known'] && $snapshot['stopped'] === true
				=> self::REFUSAL_MAILHOST_STOPPED,
			$this->valueOf($values, BackfillService::FEATURE_OPTION) !== 'Y'
				=> BackfillService::REFUSAL_FEATURE_DISABLED,
			default => null,
		};
		$this->readAt = microtime(true);

		return $this->refusal;
	}

	public function getMailhostErrorCode(): ?string
	{
		return $this->mailhostErrorCode;
	}

	public function getMailhostRevision(): ?int
	{
		return $this->mailhostRevision;
	}

	public function getSource(): string
	{
		return $this->source;
	}

	/**
	 * An option the portal has never written is answered by the default of the module, exactly
	 * as {@see Option::get()} answers it: the defaults are a file of the module and cost no query.
	 *
	 * @param array<string, string> $values
	 */
	private function valueOf(array $values, string $name): string
	{
		return $values[$name] ?? (string)(Option::getDefaults('mail')[$name] ?? 'N');
	}

	/**
	 * Both switches in one statement, by the primary key of the option storage.
	 *
	 * @return array<string, string> The names the portal has a value for, the stored value of each.
	 */
	private function readStoredOptions(): array
	{
		if ($this->optionsReader !== null)
		{
			return (array)($this->optionsReader)();
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$names = array_map(
			static fn(string $name): string => "'" . $helper->forSql($name) . "'",
			[BackfillService::MIGRATION_STOP_OPTION, BackfillService::FEATURE_OPTION],
		);

		$rows = $connection->query(
			'SELECT NAME, VALUE FROM b_option WHERE MODULE_ID = \'mail\''
			. ' AND NAME IN (' . implode(', ', $names) . ') AND SITE_ID IS NULL',
		)->fetchAll();

		$values = [];
		foreach ($rows as $row)
		{
			$values[(string)$row['NAME']] = (string)$row['VALUE'];
		}

		return $values;
	}

	/**
	 * @return array{known: bool, stopped: ?bool, revision: ?int, lastErrorCode: ?string}
	 */
	private function readMailhostSnapshot(): array
	{
		try
		{
			$snapshot = $this->mailhostSnapshotReader !== null
				? ($this->mailhostSnapshotReader)()
				: $this->readMailhostSnapshotFromModule()
			;

			return [
				'known' => (bool)($snapshot?->known ?? false),
				'stopped' => is_bool($snapshot?->stopped ?? null) ? $snapshot->stopped : null,
				'revision' => is_int($snapshot?->revision ?? null) ? $snapshot->revision : null,
				'lastErrorCode' => is_string($snapshot?->lastErrorCode ?? null)
					? $snapshot->lastErrorCode
					: null,
			];
		}
		catch (Throwable)
		{
			return $this->unknownMailhostSnapshot();
		}
	}

	private function readMailhostSnapshotFromModule(): ?object
	{
		$providerClass = 'Bitrix\\MailService\\Public\\Service\\MigrationStopSnapshotProvider';
		if (!Loader::includeModule('mailservice') || !class_exists($providerClass))
		{
			return null;
		}

		$provider = new $providerClass();

		return $provider->read();
	}

	/**
	 * @return array{known: false, stopped: null, revision: null, lastErrorCode: null}
	 */
	private function unknownMailhostSnapshot(): array
	{
		return [
			'known' => false,
			'stopped' => null,
			'revision' => null,
			'lastErrorCode' => null,
		];
	}
}
