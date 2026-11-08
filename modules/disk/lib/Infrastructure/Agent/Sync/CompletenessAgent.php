<?php
declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Agent\Sync;

use Bitrix\Disk\Infrastructure\Agent\BaseDiskAgent;
use Bitrix\Disk\Infrastructure\Agent\RunResult;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\Journal\CompletenessStepper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The regular tick behind {@see CompletenessStepper}: it holds none of the reconciliation itself, only
 * the shape an agent of the module is run in.
 *
 * One tick is bounded by a budget of its own, so the interval decides how far behind the portal the
 * three windows are allowed to fall. An hour is the same interval the two other mechanisms of the phase
 * run on, and it is bounded from above by something else: the snapshot of the codes of a root is swept
 * as an orphan after two days ({@see \Bitrix\Disk\Internal\Service\Sync\Journal\JournalCleaner::ORPHAN_RIGHTS_SNAPSHOT_HOURS}),
 * and a reconciliation reaching a finished recalculation after its snapshot had gone would publish the
 * record of that recalculation without the codes it was meant to reach.
 */
final class CompletenessAgent extends BaseDiskAgent
{
	private const LOGGER_CHANNEL = 'sync';

	private CompletenessStepper $stepper;
	private LoggerInterface $logger;

	public function __construct(?CompletenessStepper $stepper = null, ?LoggerInterface $logger = null)
	{
		$this->stepper = $stepper ?? new CompletenessStepper();
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'completeness agent');
	}

	/**
	 * Always a retry, whatever the tick met.
	 *
	 * {@see \Bitrix\Disk\Infrastructure\Agent\BaseAgent::run()} answers an empty string for a result that
	 * is not a retry, and an empty string from the function of an agent is how the platform is told to
	 * remove it. A regular agent that stopped answering because one tick raised would be gone from the
	 * portal for good, and the journal would lose the only thing that notices what never reached it.
	 */
	protected function runInternal(): RunResult
	{
		try
		{
			$this->stepper->run();
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the reconciliation of the journal did not finish its tick: {message}',
				['message' => $exception->getMessage(), 'exception' => $exception],
			);
		}

		return new RunResult(true);
	}
}
