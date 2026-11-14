<?php

namespace Bitrix\Call\Background;

use Bitrix\Call\Logger\Logger;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * The one place in the module that knows about the background queue of the core and about the
 * context of the process it runs in.
 *
 * The queue of the core is emptied by the epilogue of a web request
 * {@see \Bitrix\Main\Application::terminate()}, so a process that never sends a response - the
 * agent runner of a self-hosted installation - drops everything put into it. Work handed to this
 * dispatcher therefore goes to the queue on a hit, exactly as before, and runs inline in a console
 * process, where there is no response to protect and nothing else to run it.
 *
 * Two modes on purpose. A single one with the rule "returned no Result, call it a success" would
 * be a silent trap: any callback with a foreign signature would read as a permanent success, and
 * the module has at least two {@see \Bitrix\Call\NotifyService::sendMessage()},
 * {@see \Bitrix\Call\Integration\Chat::sendMessage()}. Splitting them makes every place of use
 * answer whether it needs the outcome.
 *
 * A job run by the dispatcher must not hand work to the dispatcher itself: the queue of the core
 * picks up jobs added from jobs, while the inline branch would recur without a stop.
 *
 * One exception, bounded at depth one: the repeat of a publication of the missed-calls counter that
 * found the lock of the badge busy {@see \Bitrix\Call\Service\CallLogService::publishCounter()}. The
 * publication runs inside jobs of this dispatcher too, so its repeat may be handed over from one. It is
 * safe because the repeat itself never hands work to this dispatcher: inline it adds a single nested call,
 * and the queue of the core gets a single job more. A repeat that finds the lock busy again arms an agent
 * of the module instead {@see \Bitrix\Call\Service\CallLogService::republishCounterAgent()}.
 *
 * @internal
 */
class JobDispatcher
{
	private const
		FORCE_DEFERRED_OPTION = 'call_force_deferred_jobs',
		JOB_FAILED = 'DEFERRED_JOB_FAILED',
		JOB_CONTRACT = 'DEFERRED_JOB_CONTRACT'
	;

	private static ?JobDispatcher $instance = null;

	private ?bool $cronRun = null;

	protected function __construct()
	{
	}

	public static function getInstance(): self
	{
		return self::$instance ??= new self();
	}

	/**
	 * Work nobody reads the outcome of: the return value of the job is ignored and a \Throwable is
	 * logged, so neither can reach the caller.
	 */
	public function enqueue(callable $job, array $args = [], int $priority = Application::JOB_PRIORITY_NORMAL): void
	{
		if (!$this->runsHere())
		{
			Application::getInstance()->addBackgroundJob($job, $args, $priority);

			return;
		}

		try
		{
			call_user_func_array($job, $args);
		}
		catch (\Throwable $e)
		{
			Logger::getInstance()->error("JobDispatcher: job failed during a cron run: " . $e);
		}
	}

	/**
	 * Work the caller needs the outcome of; the job is obliged to return a {@see Result}.
	 *
	 * The answer is as precise as the context allows: on a hit it is the success of the enqueue,
	 * because the work runs after the response by definition and no earlier answer exists, and on
	 * a cron run it is what the job itself said. The priority has no meaning in the inline branch -
	 * the order becomes the order of the calls, and the queue of the core gives jobs of one
	 * priority no order at all.
	 */
	public function enqueueWithOutcome(
		callable $job,
		array $args = [],
		int $priority = Application::JOB_PRIORITY_NORMAL,
	): Result
	{
		if (!$this->runsHere())
		{
			Application::getInstance()->addBackgroundJob($job, $args, $priority);

			return new Result();
		}

		try
		{
			$outcome = call_user_func_array($job, $args);
		}
		catch (\Throwable $e)
		{
			Logger::getInstance()->error("JobDispatcher: job failed during a cron run: " . $e);

			return (new Result())->addError(new Error('Deferred job failed', self::JOB_FAILED));
		}

		if (!$outcome instanceof Result)
		{
			Logger::getInstance()->error("JobDispatcher: job must return Result");

			return (new Result())->addError(new Error('Deferred job returned no Result', self::JOB_CONTRACT));
		}

		return $outcome;
	}

	/**
	 * This process was started from the console, so there is no HTTP request to finish.
	 *
	 * Mirrors {@see \Bitrix\Disk\Volume\Cleaner::isCronRun()}, which answers the same question the
	 * same way, minus its check for the bitrix24 module: that one rules out cloud portals as a
	 * kind of installation, while the question here is about this one process. 'cli' is compared
	 * exactly, so the built-in web server ('cli-server') is not taken for a cron run.
	 *
	 * Cached for the process: the SAPI cannot change inside it.
	 */
	public function isCronRun(): bool
	{
		return $this->cronRun ??= (php_sapi_name() === 'cli');
	}

	/**
	 * The option is read on every call and never cached - a rollback in production and the sets
	 * that watch the queue of the core both switch it at runtime.
	 */
	private function runsHere(): bool
	{
		return $this->isCronRun() && !self::deferralIsForced();
	}

	/**
	 * The switch is a value of the option table, so the stored string is compared against an
	 * allowlist and never cast: (bool)'N' would be true, and 'N' is what an administrator turns a
	 * switch of this module off with {@see \Bitrix\Call\Settings::isCallReactionsEnabled()}. Casting
	 * it would arm the switch instead of disarming it and silently bring back the very behaviour
	 * this class exists to replace.
	 */
	private static function deferralIsForced(): bool
	{
		$truthy = ['Y', '1', 1, true];

		return in_array(Option::get('call', self::FORCE_DEFERRED_OPTION, false), $truthy, true);
	}
}
