<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internal\Service\NewMail\NewMailSwitch;
use Bitrix\Mail\Public\Features\Bitrix24MailFeature;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature\Context;
use Bitrix\Main\Config\Feature\Factory;
use Bitrix\Main\Config\Feature\RulePolicy;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Closure;
use Throwable;

/**
 * The switches of the rollout - the emergency brake, the stop of Mailhost and the flag of the New mail - as
 * they stand right now. The emergency brake and the stored state of the flag are read past the caches of the
 * process.
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
 * The flag of the New mail is asked twice. {@see NewMailSwitch::isAccepting()} gives the answer of the core,
 * rules included, but the core keeps the values of the flags in memory for the whole process and drops them only
 * in the process that switched a flag: a pass of the agent would go on seeing the flag it started with. So the
 * row of the flag and its rules are read in the same query as the brake and decided in the order of the core -
 * requirements, deny rules, allow rules, the row, the default - and a flag switched off in any other process stops
 * the pass at the next fresh read. The fresh answer can only close: a flag switched on after the process started
 * is admitted by the next process, whose core sees it. A portal without the storage of the flags is answered by
 * the core alone.
 *
 * The conditions and their order are the ones of {@see BackfillService::findMigrationRefusal()} -
 * this is a second way to read the same switches, never a second rule about them.
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

	private readonly Closure $featureReader;

	/**
	 * @param Closure|null $freshFlagReader Answers the flag as the core would decide it on the storage as it is now,
	 *     rules included, for an injected options reader: true or false, null when it is unknown.
	 */
	public function __construct(
		private readonly ?Closure $optionsReader = null,
		private readonly ?Closure $mailhostSnapshotReader = null,
		?Closure $featureReader = null,
		private readonly ?Closure $freshFlagReader = null,
	)
	{
		$this->featureReader = $featureReader ?? static fn(): bool => (new NewMailSwitch())->isAccepting();
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
		[$values, $freshFlag] = $this->readStoredSwitches();
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
			$freshFlag === false || ($this->featureReader)() !== true
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
	 * The emergency brake by the primary key of the option storage, the flag by the primary key of the storage of the
	 * flags and the rules of the flag by its code, in one query: a row per rule, or a single row without one.
	 *
	 * @return array{0: array<string, string>, 1: ?bool} The names the portal has a value for with the stored value
	 *     of each, and the flag as the core decides it on the storage as it is now, null when it cannot be read.
	 */
	private function readStoredSwitches(): array
	{
		if ($this->optionsReader !== null)
		{
			$flag = $this->freshFlagReader !== null ? ($this->freshFlagReader)() : null;

			return [(array)($this->optionsReader)(), is_bool($flag) ? $flag : null];
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$brakeQuery = 'SELECT VALUE FROM b_option WHERE MODULE_ID = \'mail\''
			. ' AND NAME = \'' . $helper->forSql(BackfillService::MIGRATION_STOP_OPTION) . '\' AND SITE_ID IS NULL';
		$flagCode = $helper->forSql(Bitrix24MailFeature::class);

		try
		{
			$rows = $connection->query(
				'SELECT S.STOP_VALUE, S.FLAG_ENABLED, R.POLICY, R.RULE_CODE, R.RULE_ARGS'
				. ' FROM (SELECT (' . $brakeQuery . ') AS STOP_VALUE,'
				. ' (SELECT ENABLED FROM b_feature_flag WHERE CODE = \'' . $flagCode . '\') AS FLAG_ENABLED) S'
				. ' LEFT JOIN b_feature_flag_rule R ON R.FEATURE_CODE = \'' . $flagCode . '\''
				. ' ORDER BY R.ID DESC',
			)->fetchAll();
		}
		catch (Throwable)
		{
			$rows = null;
		}

		if ($rows === null || $rows === [])
		{
			$flag = null;
			$stopValue = $connection->queryScalar($brakeQuery);
		}
		else
		{
			$flagValue = $rows[0]['FLAG_ENABLED'] ?? null;
			$flag = $this->decideFlag($flagValue === null ? null : $flagValue === 'Y', $rows);
			$stopValue = $rows[0]['STOP_VALUE'] ?? null;
		}

		$values = [];
		if ($stopValue !== null && $stopValue !== false)
		{
			$values[BackfillService::MIGRATION_STOP_OPTION] = (string)$stopValue;
		}

		return [$values, $flag];
	}

	/**
	 * The decision of {@see \Bitrix\Main\Config\Feature\FeatureManager::isEnabled()} over the rows read right now,
	 * asked for the portal and not for the user of the hit, as {@see NewMailSwitch} asks it. A rule is rebuilt and
	 * checked by the core itself; a rule the core cannot resolve is skipped, as the core skips it.
	 *
	 * @param array<int, array<string, mixed>> $rules
	 */
	private function decideFlag(?bool $stored, array $rules): bool
	{
		$flag = new Bitrix24MailFeature();
		$context = new Context();

		foreach ($flag->getRequirements() as $requirement)
		{
			if (is_callable($requirement) && !$requirement($context))
			{
				return false;
			}
		}

		$factory = new Factory();
		$matched = [];

		foreach ($rules as $rule)
		{
			$policy = RulePolicy::tryFrom((string)($rule['POLICY'] ?? ''));
			$code = (string)($rule['RULE_CODE'] ?? '');
			if ($policy === null || $code === '' || isset($matched[$policy->value]))
			{
				continue;
			}

			$instance = $factory->resolveRule($code, $this->decodeRuleArgs($rule['RULE_ARGS'] ?? null));
			if ($instance !== null && $instance->check($context))
			{
				$matched[$policy->value] = true;
			}
		}

		return match (true)
		{
			isset($matched[RulePolicy::DENY->value]) => false,
			isset($matched[RulePolicy::ALLOW->value]) => true,
			default => $stored ?? $flag->enabledByDefault(),
		};
	}

	private function decodeRuleArgs(mixed $args): array
	{
		if (!is_string($args) || $args === '')
		{
			return [];
		}

		try
		{
			$decoded = Json::decode($args);
		}
		catch (Throwable)
		{
			return [];
		}

		return is_array($decoded) ? $decoded : [];
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
