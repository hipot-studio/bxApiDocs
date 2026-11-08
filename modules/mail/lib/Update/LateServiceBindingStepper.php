<?php

declare(strict_types=1);

namespace Bitrix\Mail\Update;

use Bitrix\Mail\Internal\Service\SourceGeneration\LateServiceBinder;
use Bitrix\Main;
use Bitrix\Main\Config\Option;

/**
 * Resolves late service bindings in bounded background steps.
 *
 * Primary-key windows bound the unindexed scan, while a per-step match limit bounds locking and writes.
 * Deferred windows are retried in short laps and hourly rounds. After the final round, unresolved
 * generations are journaled with the same bounds before the request is removed.
 */
final class LateServiceBindingStepper extends Main\Update\Stepper
{
	/** A unique value identifies each requested walk. */
	public const REQUEST_OPTION = 'late_service_binding_request';

	/** Primary-key range scanned by one step. */
	private const WINDOW = 10000;

	/** Matching generations processed by one step. */
	private const GENERATIONS_PER_STEP = 100;

	/** Immediate retry passes over deferred windows. */
	private const LAPS = 3;

	/** Consecutive no-progress rounds before unresolved generations are journaled. */
	private const ROUNDS = 24;

	/** Delay between retry rounds, in seconds. */
	private const ROUND_PAUSE = 3600;

	protected static $moduleId = 'mail';

	/** Serializes request replacement and completion. */
	private const LOCK_NAME = 'mail_late_service_binding';
	private static bool $lockHeld = false;

	public static function request(): void
	{
		self::underLock(static function (): void {
			$request = Main\UuidGenerator::generateV4();
			Option::set(self::getModuleId(), self::REQUEST_OPTION, $request);
			self::scheduleAgent($request, 300);
		});
	}

	// Each request owns its agent and state: CAgent deletes a finished agent after this method returns.
	public static function execAgent()
	{
		global $pPERIOD;

		$request = (string)(func_get_args()[0] ?? '');
		if ($request === '')
		{
			self::underLock(static function (): void {
				$current = self::readRequest();
				if ($current !== '')
				{
					self::scheduleAgent($current, 1);
				}
			});

			return '';
		}

		$category = 'mail.late_service_binding';
		if ($request !== self::readRequest())
		{
			Option::delete($category, ['name' => $request]);

			return '';
		}

		$option = unserialize(self::readOption($category, $request), ['allowed_classes' => false]);
		$option = is_array($option) ? $option : [];
		$updater = new self();
		$updater->setOuterParams([$request]);
		$started = microtime(true);
		$continue = $updater->execute($option);

		if ($continue === self::CONTINUE_EXECUTION && self::readRequest() === $request)
		{
			$elapsed = microtime(true) - $started;
			if ($elapsed > self::THRESHOLD_TIME)
			{
				$pPERIOD = (int)round($elapsed * (1 + self::DELAY_COEFFICIENT));
			}
			Option::set($category, $request, serialize($option));

			return self::class . '::execAgent(' . self::makeArguments([$request]) . ');';
		}

		Option::delete($category, ['name' => $request]);

		return '';
	}

	private static function underLock(callable $callback): mixed
	{
		if (self::$lockHeld)
		{
			return $callback();
		}

		$connection = Main\Application::getConnection();
		if (!$connection->lock(self::LOCK_NAME, 60))
		{
			throw new Main\SystemException('Cannot lock the late service binding request');
		}

		self::$lockHeld = true;
		try
		{
			return $callback();
		}
		finally
		{
			self::$lockHeld = false;
			$connection->unlock(self::LOCK_NAME);
		}
	}

	private static function scheduleAgent(string $request, int $delay): void
	{
		$legacyCategory = 'main.stepper.' . self::getModuleId();
		$legacy = self::readOption($legacyCategory, self::class);
		$legacyState = unserialize($legacy, ['allowed_classes' => false]);
		if (is_array($legacyState) && ($legacyState['request'] ?? '') === $request
			&& self::readOption('mail.late_service_binding', $request) === '')
		{
			Option::set('mail.late_service_binding', $request, $legacy);
		}

		\CTimeZone::Disable();
		try
		{
			\CAgent::AddAgent(
				self::class . '::execAgent(' . self::makeArguments([$request]) . ');',
				self::getModuleId(),
				'Y',
				1,
				'',
				'Y',
				date(Main\Type\Date::convertFormatToPhp(\CSite::GetDateFormat()), time() + $delay),
				100,
				false,
				false,
			);
		}
		finally
		{
			\CTimeZone::Enable();
		}

		Option::delete('main.stepper.' . self::getModuleId(), ['name' => self::class]);
		Option::delete('main.stepper.' . self::getModuleId(), ['name' => '\\' . self::class]);
	}

	private static function readRequest(): string
	{
		return self::readOption(self::getModuleId(), self::REQUEST_OPTION);
	}

	private static function readOption(string $moduleId, string $name): string
	{
		$connection = Main\Application::getConnection();
		$helper = $connection->getSqlHelper();
		$row = $connection->query(
			"SELECT VALUE FROM b_option WHERE MODULE_ID = '" . $helper->forSql(mb_strtolower($moduleId))
			. "' AND NAME = '" . $helper->forSql(mb_strtolower($name)) . "' AND SITE_ID IS NULL",
		)->fetch();

		return (string)($row['VALUE'] ?? '');
	}

	private function finishRequest(string $request): bool
	{
		return self::underLock(static function () use ($request): bool {
			if (self::readRequest() !== $request)
			{
				return self::CONTINUE_EXECUTION;
			}

			Option::delete(self::getModuleId(), ['name' => self::REQUEST_OPTION]);

			return self::FINISH_EXECUTION;
		});
	}

	public function execute(array &$option)
	{
		$request = self::readRequest();
		if ($request === '' || (isset($this->outerParams[0]) && $this->outerParams[0] !== $request))
		{
			return self::FINISH_EXECUTION;
		}

		$binder = new LateServiceBinder();

		// A new request restarts the scan because its service row may affect an earlier generation.
		if (($option['request'] ?? '') !== $request)
		{
			$this->startTheWalk($option, $request, $binder->lastGenerationId());
		}

		if ($this->isSleepingBetweenRounds($option))
		{
			return self::CONTINUE_EXECUTION;
		}

		if ((int)($option['namingTo'] ?? 0) > 0)
		{
			return $this->nameOneWindowOfWhatIsLeftBehind($option, $binder, $request);
		}

		$windowEnd = min((int)$option['lastId'] + self::WINDOW, (int)$option['upToId']);
		$reachedId = $windowEnd;
		$deferred = 0;

		try
		{
			$walked = $binder->bindGenerationsOfWindow(
				(int)$option['lastId'],
				$windowEnd,
				self::GENERATIONS_PER_STEP,
			);

			$deferred = $walked['deferred'];
			$reachedId = $walked['reachedId'];
			$option['boundThisRound'] = ($option['boundThisRound'] ?? false) || $walked['bound'] > 0;
		}
		catch (\Throwable)
		{
			// Retry the entire window after any read or write failure.
			$deferred = 1;
		}

		if ($deferred > 0)
		{
			$this->rememberOwedWindow($option, $reachedId);
		}

		$option['lastId'] = $reachedId;
		$option['steps'] = (int)$option['steps'] + 1;

		if ($reachedId < (int)$option['upToId'])
		{
			return self::CONTINUE_EXECUTION;
		}

		if (self::readRequest() !== $request)
		{
			// Another row appeared while this walk ran: the next step starts a walk for it.
			return self::CONTINUE_EXECUTION;
		}

		if ($this->lapOverWhatIsOwed($option))
		{
			return self::CONTINUE_EXECUTION;
		}

		if ($this->sleepUntilTheNextRound($option))
		{
			return self::CONTINUE_EXECUTION;
		}

		return $this->finishRequest($request);
	}

	/** Resets all cursors and retry state for a new request. */
	private function startTheWalk(array &$option, string $request, int $lastGenerationId): void
	{
		$option['request'] = $request;
		$option['lastId'] = 0;
		$option['upToId'] = $lastGenerationId;
		$option['laps'] = 0;
		$option['rounds'] = 0;
		$option['boundThisRound'] = false;
		$option['sleepUntil'] = 0;
		$option['owedFrom'] = 0;
		$option['owedTo'] = 0;
		$option['namingFrom'] = 0;
		$option['namingTo'] = 0;
		$option['steps'] = 0;
		$option['count'] = (int)ceil($lastGenerationId / self::WINDOW);
	}

	/** Expands the deferred range through the current cursor. */
	private function rememberOwedWindow(array &$option, int $reachedId): void
	{
		if ((int)($option['owedTo'] ?? 0) <= 0)
		{
			$option['owedFrom'] = (int)$option['lastId'];
		}

		$option['owedTo'] = $reachedId;
	}

	/** Returns whether another immediate retry lap was started. */
	private function lapOverWhatIsOwed(array &$option): bool
	{
		if ((int)($option['owedTo'] ?? 0) <= 0 || (int)($option['laps'] ?? 0) >= self::LAPS)
		{
			return false;
		}

		$option['laps'] = (int)($option['laps'] ?? 0) + 1;
		$this->turnBackOntoWhatIsOwed($option);

		return true;
	}

	/**
	 * Schedules another round or starts bounded journaling after the no-progress limit.
	 * Progress resets the round counter so a large backlog cannot exhaust retries while shrinking.
	 */
	private function sleepUntilTheNextRound(array &$option): bool
	{
		$owedTo = (int)($option['owedTo'] ?? 0);
		if ($owedTo <= 0)
		{
			return false;
		}

		$rounds = ($option['boundThisRound'] ?? false) ? 0 : (int)($option['rounds'] ?? 0) + 1;

		if ($rounds >= self::ROUNDS)
		{
			$this->turnOntoNamingWhatIsLeftBehind($option);

			return true;
		}

		$option['rounds'] = $rounds;
		$option['boundThisRound'] = false;
		$option['laps'] = 0;
		$option['sleepUntil'] = time() + self::ROUND_PAUSE;
		$this->putTheAgentBackBy(self::ROUND_PAUSE);
		$this->turnBackOntoWhatIsOwed($option);

		return true;
	}

	/** Whether the pause between two rounds is still running, and this step therefore has nothing to do. */
	private function isSleepingBetweenRounds(array &$option): bool
	{
		$sleepUntil = (int)($option['sleepUntil'] ?? 0);
		$left = $sleepUntil - time();

		if ($left <= 0)
		{
			return false;
		}

		// Keep the persisted deadline authoritative when overdue agents resume.
		$this->putTheAgentBackBy($left);

		return true;
	}

	/** Delays the next agent execution. */
	private function putTheAgentBackBy(int $seconds): void
	{
		global $pPERIOD;

		$pPERIOD = max(1, $seconds);
	}

	/** Restores the deferred range as the active scan range. */
	private function turnBackOntoWhatIsOwed(array &$option): void
	{
		$owedFrom = (int)($option['owedFrom'] ?? 0);
		$owedTo = (int)($option['owedTo'] ?? 0);

		$option['lastId'] = $owedFrom;
		$option['upToId'] = $owedTo;
		$option['owedFrom'] = 0;
		$option['owedTo'] = 0;
		$option['count'] = (int)$option['steps'] + (int)ceil(($owedTo - $owedFrom) / self::WINDOW);
	}

	/** Switches from binding to bounded journaling over the full deferred range. */
	private function turnOntoNamingWhatIsLeftBehind(array &$option): void
	{
		$owedFrom = (int)($option['owedFrom'] ?? 0);
		$owedTo = (int)($option['owedTo'] ?? 0);

		$option['namingFrom'] = $owedFrom;
		$option['namingTo'] = $owedTo;
		$option['owedFrom'] = 0;
		$option['owedTo'] = 0;
		$option['count'] = (int)$option['steps'] + (int)ceil(($owedTo - $owedFrom) / self::WINDOW);
	}

	/** Processes one journaling window and returns whether the walk must continue. */
	private function nameOneWindowOfWhatIsLeftBehind(
		array &$option,
		LateServiceBinder $binder,
		string $request,
	): bool
	{
		$windowEnd = min((int)$option['namingFrom'] + self::WINDOW, (int)$option['namingTo']);
		$reachedId = $windowEnd;

		try
		{
			$named = $binder->journalGenerationsLeftBehind(
				(int)$option['namingFrom'],
				$windowEnd,
				self::GENERATIONS_PER_STEP,
			);

			$reachedId = $named['reachedId'];
		}
		catch (\Throwable)
		{
			// Continue after a journaling failure so later windows still produce diagnostics.
		}

		$option['namingFrom'] = $reachedId;
		$option['steps'] = (int)$option['steps'] + 1;

		if ($reachedId < (int)$option['namingTo'])
		{
			return self::CONTINUE_EXECUTION;
		}

		if (self::readRequest() !== $request)
		{
			// A newer request restarts binding after this journaling phase.
			return self::CONTINUE_EXECUTION;
		}

		return $this->finishRequest($request);
	}
}
