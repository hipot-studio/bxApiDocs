<?php
declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Agent\Sync;

use Bitrix\Disk\Infrastructure\Agent\BaseDiskAgent;
use Bitrix\Disk\Infrastructure\Agent\RunResult;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\Visibility\InteractionAger;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The regular tick behind {@see InteractionAger}: it holds no logic of the aging itself, only the shape
 * an agent of the module is run in.
 *
 * The walk it drives is bounded by a budget, so the agent is registered often rather than rarely - the
 * backlog a portal meets when the window is first introduced is drained tick by tick, and an interval of
 * a day would drain it over weeks. Ageing to the hour is precise enough for a window measured in days.
 */
final class InteractionAgingAgent extends BaseDiskAgent
{
	private const LOGGER_CHANNEL = 'sync';

	private InteractionAger $ager;
	private LoggerInterface $logger;

	public function __construct(?InteractionAger $ager = null, ?LoggerInterface $logger = null)
	{
		$this->ager = $ager ?? new InteractionAger();
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'interaction aging agent');
	}

	/**
	 * Always a retry, whatever the tick met.
	 *
	 * {@see \Bitrix\Disk\Infrastructure\Agent\BaseAgent::run()} answers an empty string for a result that
	 * is not a retry, and an empty string from the function of an agent is how the platform is told to
	 * remove it. A regular agent that stopped answering because one tick raised would be gone from the
	 * portal for good, and the set would silently go back to never ageing.
	 */
	protected function runInternal(): RunResult
	{
		try
		{
			$this->ager->run();
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the aging of the interaction set did not finish its tick: {message}',
				['message' => $exception->getMessage(), 'exception' => $exception],
			);
		}

		return new RunResult(true);
	}
}
