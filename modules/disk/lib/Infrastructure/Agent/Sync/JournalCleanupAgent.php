<?php
declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Agent\Sync;

use Bitrix\Disk\Infrastructure\Agent\BaseDiskAgent;
use Bitrix\Disk\Infrastructure\Agent\RunResult;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\Journal\JournalCleaner;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The regular tick behind {@see JournalCleaner}: it holds none of the cleanup itself, only the shape an
 * agent of the module is run in.
 *
 * One tick is bounded by a budget of its own, so the interval is what decides how much a portal gets
 * through in a day rather than how old a row is when it goes. An hour keeps the walk well ahead of the
 * write rate of a large portal without a tick ever being long.
 */
final class JournalCleanupAgent extends BaseDiskAgent
{
	private const LOGGER_CHANNEL = 'sync';

	private JournalCleaner $cleaner;
	private LoggerInterface $logger;

	public function __construct(?JournalCleaner $cleaner = null, ?LoggerInterface $logger = null)
	{
		$this->cleaner = $cleaner ?? new JournalCleaner();
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'journal cleanup agent');
	}

	/**
	 * Always a retry, whatever the tick met.
	 *
	 * {@see \Bitrix\Disk\Infrastructure\Agent\BaseAgent::run()} answers an empty string for a result that
	 * is not a retry, and an empty string from the function of an agent is how the platform is told to
	 * remove it. A regular agent that stopped answering because one tick raised would be gone from the
	 * portal for good, and the journal would grow without a bound from then on - the one thing the
	 * bounded storage of this contour rests on.
	 */
	protected function runInternal(): RunResult
	{
		try
		{
			$this->cleaner->run();
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the cleanup of the journal did not finish its tick: {message}',
				['message' => $exception->getMessage(), 'exception' => $exception],
			);
		}

		return new RunResult(true);
	}
}
