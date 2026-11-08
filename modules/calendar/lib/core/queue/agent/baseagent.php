<?php

namespace Bitrix\Calendar\Core\Queue\Agent;

use Bitrix\Calendar\Core\Queue\Interfaces;
use Bitrix\Calendar\Core\Queue\Interfaces\Processor;
use Bitrix\Calendar\Core\Queue\LogContext;
use Bitrix\Calendar\Core\Queue\QueueLogger;
use CAgent;
use Psr\Log\LoggerInterface;
use Throwable;

abstract class BaseAgent
{
	private const TIME_LIMIT = 10;
	private static ?LoggerInterface $logger = null;

	protected bool $isEscalated = false;

	/**
	 * @return string
	 */
	public static function runAgent(): string
	{
		try
		{
			$runner = new static();
			$runner->run();
			self::modifyAgent($runner);
		}
		catch(Throwable $exception)
		{
			self::logErrorSafely('Queue agent failed', ['exception' => LogContext::fromThrowable($exception)]);
		}

		return static::getAgentName();
	}

	/**
	 * The journal lives in the database as well, and the container it comes from can fail on its
	 * own. Either failure escaping here would cost the agent the name it returns, and the core
	 * reschedules the agent by that name alone: without it the row keeps the DATE_CHECK of the pass
	 * that claimed it and the agent stays silent for the whole lock time.
	 */
	private static function logErrorSafely(string $message, array $context): void
	{
		try
		{
			if (is_null(self::$logger))
			{
				self::$logger = QueueLogger::get();
			}

			self::$logger->error($message, $context);
		}
		catch (Throwable)
		{
		}
	}

	/**
	 * @return string
	 */
	protected static function getAgentName(): string
	{
		return static::class . "::runAgent();";
	}

	/**
	 * @return string
	 */
	protected static function getModule(): string
	{
		return 'calendar';
	}

	/**
	 * @param BaseAgent $runner
	 *
	 * @return void
	 */
	private static function modifyAgent(self $runner)
	{
		$agent = CAgent::getList(
			[],
			[
				'MODULE_ID' => self::getModule(),
				'=NAME' =>self::getAgentName(),
			],
		)->Fetch();
		if ($agent)
		{
			$interval = $runner->isEscalated
				? $runner->getEscalatedInterval()
				: $runner->getInterval();
			if ((int)$agent['AGENT_INTERVAL'] !== $interval)
			{
				CAgent::Update($agent['ID'],['AGENT_INTERVAL' => $interval]);
			}
		}
	}

	/**
	 * @return void
	 */
	protected function run()
	{
		$consumer = $this->getConsumer();
		$processor = $this->getProcessor();

		$startTime = time();
		$this->deescalateMe();
		while ($message = $consumer->receive())
		{
			$result = $processor->process($message);

			if ($result === Interfaces\Processor::ACK)
			{
				$consumer->acknowledge($message);
			}
			elseif ($result === Interfaces\Processor::REJECT)
			{
				$consumer->reject($message);
			}

			$this->escalateMe();
			if ((time() - $startTime) > $this->getTimeLimit())
			{
				break;
			}
		}
	}

	/**
	 * @return void
	 */
	protected function escalateMe(): void
	{
		$this->isEscalated = true;
	}

	/**
	 * @return void
	 */
	protected function deescalateMe(): void
	{
		$this->isEscalated = false;
	}

	/**
	 * @return int
	 */
	protected function getInterval(): int
	{
		return 3600;
	}

	/**
	 * @return int
	 */
	protected function getEscalatedInterval(): int
	{
		return 60;
	}

	/**
	 * @return int
	 */
	protected function getTimeLimit(): int
	{
		return self::TIME_LIMIT;
	}

	/**
	 * @return Interfaces\Consumer
	 */
	abstract protected function getConsumer(): Interfaces\Consumer;

	/**
	 * @return Processor
	 */
	abstract protected function getProcessor(): Interfaces\Processor;
}
