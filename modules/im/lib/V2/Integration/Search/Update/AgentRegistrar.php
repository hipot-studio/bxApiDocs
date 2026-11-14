<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Update;

use Bitrix\Im\V2\Logger;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Update\Stepper;
use Bitrix\Main\Web\Json;

/**
 * Registration point of the agents of the domain and the operational entry of its one-off walks.
 *
 * None of them is registered by the installer or by an updater: a walk is started by hand on a
 * particular portal, and the conversion rebake additionally by the conversion itself.
 *
 * A repeated register* call while the agent of that stepper stands is a no-op that answers with the
 * current status: the progress of a walk lives in the option, and a second agent would only walk the
 * same state twice.
 */
final class AgentRegistrar
{
	private const MODULE_ID = 'im';
	private const STEPPER_OPTION_CATEGORY = 'main.stepper.' . self::MODULE_ID;
	private const LOG_TAG = 'message-search-agents';

	/**
	 * The core lengthens the period of an agent after a step longer than this. Left at the standard
	 * 20 s it would never trigger: a portion is a matter of seconds, while every command behind it
	 * queues a walk over the entries of a chat.
	 */
	private const STEPPER_THRESHOLD_TIME = 5.0;

	/**
	 * Raises the walk of the archive: the whole of it without a bound, its tail with one.
	 *
	 * The bound serves the catch-up runs and a bulk import alike: the import path writes past the
	 * online pipeline, so its messages are caught up by a walk bounded by the last message id from
	 * before the import.
	 *
	 * The completion mark is not touched here. Whether a call without a bound turns out to be the full
	 * walk is decided by ALG-06 at the first step and not at the registration: an index that already
	 * holds entries of the module is caught up from its last entry, and that is not a walk the mark
	 * describes. The mark is owned by the stepper alone - it writes it when a full walk reaches the end
	 * of the archive and takes it off when the walk starts out over an empty index. Taking it off here
	 * left a portal whose walk turned out to be a catch-up 'not ready' with nothing to make it ready
	 * again, and did so even when the call registered no agent at all because one was already standing.
	 *
	 * @param int|null $lowerBoundMessageId Message the walk stops at.
	 * @param DateTime|null $lowerBoundDate Moment the walk stops at; ignored when an id is given.
	 * @return array Status of the steppers of the domain.
	 */
	public static function registerArchiveBackfill(
		?int $lowerBoundMessageId = null,
		?DateTime $lowerBoundDate = null,
	): array
	{
		$boundary = self::resolveBoundaryArgument($lowerBoundMessageId, $lowerBoundDate);

		self::register(
			ArchiveBackfillStepper::class,
			ArchiveBackfillStepper::getPeriod(),
			$boundary === null ? [] : [$boundary],
		);

		return self::status();
	}

	/**
	 * Raises the walk that grants the shared access code to the entries of the open contexts the
	 * portal collected before that code existed.
	 *
	 * @return array Status of the steppers of the domain.
	 */
	public static function registerOpenContextPermission(): array
	{
		self::register(OpenContextPermissionStepper::class, OpenContextPermissionStepper::getPeriod());

		return self::status();
	}

	/**
	 * Raises the walk that rebuilds the entries of the comments: the addressing by the parent channel
	 * and the rights inherited from it.
	 *
	 * @return array Status of the steppers of the domain.
	 */
	public static function registerCommentReindex(): array
	{
		self::register(CommentReindexStepper::class, CommentReindexStepper::getPeriod());

		return self::status();
	}

	/**
	 * Raises the walk that takes the classes the product registry does not index out of an index
	 * collected by foreign code. The completion mark of the previous cleanup goes off with the call:
	 * the number of entries it removed says nothing about the new walk.
	 *
	 * @return array Status of the steppers of the domain.
	 */
	public static function registerHygieneCleanup(): array
	{
		Option::delete(self::MODULE_ID, ['name' => HygieneCleanupStepper::COMPLETED_OPTION]);

		self::register(HygieneCleanupStepper::class, HygieneCleanupStepper::getPeriod());

		return self::status();
	}

	/**
	 * Queues a chat converted from open into private and makes sure the walk has an agent to run in.
	 *
	 * Without an argument nothing is queued: this is the operational entry that raises the walk over
	 * a queue left behind by a disabled pipeline flag or by a removed agent.
	 */
	public static function registerChatConversionRebake(?int $chatId = null): void
	{
		if ($chatId !== null && $chatId > 0)
		{
			ChatConversionPermissionStepper::enqueue($chatId);
		}

		self::registerQueueWalk();
	}

	/**
	 * Makes sure the walk of the queue has an agent to run in. Everything a job of the write path could
	 * not carry out - a deletion left over its budget, a refused command of the rights, an entry the
	 * index would not write - stands in the same queue as the rebake of a conversion, so the agent is
	 * the same one and a second call while it stands changes nothing.
	 */
	public static function registerQueueRemainder(): void
	{
		self::registerQueueWalk();
	}

	/**
	 * Raises the agent of the queue walk for a caller that already holds the lock of the queue. The one
	 * such caller is the finishing step of the walk: it takes its agent off under that lock and raises a
	 * new one right there when a change was parked while it held it.
	 */
	public static function raiseQueueWalkUnderHeldLock(): void
	{
		self::raiseQueueWalk();
	}

	/**
	 * Raises the agent of the queue walk for a change that has just been parked. The lock is not waited
	 * for a second time: the parking is what a lock refused three times over leads to, and the safety of
	 * registering without it is the order the parking keeps - the row is written before the agent is
	 * read, so a finishing step that missed the row has already taken its agent off by then.
	 */
	public static function raiseQueueWalkForParkedChange(): void
	{
		self::raiseQueueWalk();
	}

	private static function raiseQueueWalk(): void
	{
		self::register(
			ChatConversionPermissionStepper::class,
			ChatConversionPermissionStepper::getPeriod(),
		);
	}

	/**
	 * The way the agent of the queue walk is raised by a caller whose change reached the queue itself,
	 * and it goes under the lock of that queue: a step that found the queue empty takes its agent off
	 * under the same lock, so a registration serialised with it either stands beside work the step still
	 * sees or raises an agent of its own.
	 */
	private static function registerQueueWalk(): void
	{
		ChatConversionPermissionStepper::registerUnderQueueLock(static fn () => self::raiseQueueWalk());
	}

	/**
	 * Takes a chat off the queue when it turns open again: entries of an open context are reached by
	 * the shared code, and baking names into them is what the model of rights forbids.
	 */
	public static function cancelChatConversionRebake(int $chatId): void
	{
		if ($chatId > 0)
		{
			ChatConversionPermissionStepper::dequeue($chatId);
		}
	}

	/**
	 * Stops a walk without losing its progress: the state of the stepper stays in the option, so a
	 * later registration goes on from the position reached and not from the beginning.
	 */
	public static function remove(string $stepperClass): void
	{
		foreach (self::findAgents($stepperClass) as $agent)
		{
			// the exact name, taken from the row: the walk of the archive is named with its lower bound
			\CAgent::RemoveAgent((string)$agent['NAME'], self::MODULE_ID);
		}
	}

	/**
	 * @return array Per stepper: whether its agent stands, the progress of the walk and, for the two
	 *               walks that have one, the completion mark that outlives the state of the stepper.
	 */
	public static function status(): array
	{
		return [
			ArchiveBackfillStepper::class => self::describe(ArchiveBackfillStepper::class) + [
				'completed' => self::readCompletionMark(ArchiveBackfillStepper::COMPLETED_OPTION),
			],
			OpenContextPermissionStepper::class => self::describe(OpenContextPermissionStepper::class),
			CommentReindexStepper::class => self::describe(CommentReindexStepper::class),
			HygieneCleanupStepper::class => self::describe(HygieneCleanupStepper::class) + [
				'completed' => self::readCompletionMark(HygieneCleanupStepper::COMPLETED_OPTION),
			],
			ChatConversionPermissionStepper::class => self::describe(ChatConversionPermissionStepper::class) + [
				'queue' => ChatConversionPermissionStepper::getQueue(),
			],
		];
	}

	/**
	 * Raises the agent of a walk unless one already stands. The status is deliberately not read here:
	 * the conversion rebake raises its agent from the request path, where reading the status of every
	 * stepper would cost a handful of queries.
	 *
	 * @param array<int|string> $arguments Arguments of the agent, reproduced by execAgent() on every run.
	 */
	private static function register(string $stepperClass, int $period, array $arguments = []): void
	{
		if (self::findAgents($stepperClass) !== [])
		{
			// said out loud: the progress of the walk lives in the option, so a second agent would only
			// walk the same state twice, and an operator asking for a walk of his own gets nothing
			(new Logger(self::LOG_TAG))->log(
				"the agent of {$stepperClass} already stands, the call registered nothing"
			);

			return;
		}

		self::seedStepperState($stepperClass);
		self::addAgent($stepperClass, $period, $arguments);
	}

	private static function describe(string $stepperClass): array
	{
		return [
			'agentRegistered' => self::findAgents($stepperClass) !== [],
			'progress' => self::readStepperState($stepperClass),
		];
	}

	/**
	 * The message the walk stops at. A date is turned into an id once: an argument of an agent carries
	 * integers and strings, and resolving the same date on every step would let the bound drift along
	 * with the archive.
	 */
	private static function resolveBoundaryArgument(?int $lowerBoundMessageId, ?DateTime $lowerBoundDate): ?int
	{
		if ($lowerBoundMessageId !== null && $lowerBoundMessageId > 0)
		{
			return $lowerBoundMessageId;
		}

		if ($lowerBoundDate !== null)
		{
			return ArchiveBackfillStepper::resolveBoundaryOfDate($lowerBoundDate);
		}

		return null;
	}

	/**
	 * Seeds the state so that the admin indicator shows the operation from the very first run and not
	 * after the first finished step.
	 */
	private static function seedStepperState(string $stepperClass): void
	{
		if (Option::get(self::STEPPER_OPTION_CATEGORY, $stepperClass, '') !== '')
		{
			return;
		}

		$state = [
			'steps' => 0,
			'count' => 0,
			'thresholdTime' => self::STEPPER_THRESHOLD_TIME,
		];

		Option::set(self::STEPPER_OPTION_CATEGORY, $stepperClass, serialize($state));
	}

	/**
	 * Stepper::bindClass() is deliberately not used: it registers an agent with an interval of one
	 * second and treats its own argument as the moment of the first run, not as the period.
	 *
	 * @param array<int|string> $arguments
	 */
	private static function addAgent(string $stepperClass, int $period, array $arguments = []): void
	{
		\CAgent::AddAgent(
			self::getAgentName($stepperClass, $arguments),
			self::MODULE_ID,
			'N',
			$period,
			'',
			'Y',
			ConvertTimeStamp(time() + \CTimeZone::GetOffset() + $period, 'FULL'),
			100,
			false,
			false,
		);
	}

	/**
	 * Agents of the stepper whatever arguments they carry: the walk of the archive is named with its
	 * lower bound, so an exact name would find it only by accident.
	 *
	 * @return array[] Rows of b_agent.
	 */
	private static function findAgents(string $stepperClass): array
	{
		$agents = \CAgent::GetList(
			[],
			[
				'NAME' => self::getAgentNamePrefix($stepperClass) . '%',
				'MODULE_ID' => self::MODULE_ID,
			],
		);

		$rows = [];
		while ($agent = $agents->Fetch())
		{
			$rows[] = $agent;
		}

		return $rows;
	}

	private static function readStepperState(string $stepperClass): array
	{
		$state = Option::get(self::STEPPER_OPTION_CATEGORY, $stepperClass, '');
		if ($state === '')
		{
			return [];
		}

		$state = unserialize($state, ['allowed_classes' => false]);

		return is_array($state) ? $state : [];
	}

	private static function readCompletionMark(string $optionName): ?array
	{
		$mark = Option::get(self::MODULE_ID, $optionName, '');
		if ($mark === '')
		{
			return null;
		}

		try
		{
			$decoded = Json::decode($mark);
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * The one spelling of the name: a leading slash or an extra space would make the core add a second
	 * agent beside the existing one.
	 *
	 * @param array<int|string> $arguments
	 */
	private static function getAgentName(string $stepperClass, array $arguments = []): string
	{
		return self::getAgentNamePrefix($stepperClass) . Stepper::makeArguments($arguments) . ');';
	}

	private static function getAgentNamePrefix(string $stepperClass): string
	{
		return $stepperClass . '::execAgent(';
	}
}
