<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\AiSummary;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main\Diag\LoggerFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Asks for the summary of a client on behalf of the tab of the call card ([P1.T4]).
 *
 * There is no chat here, and that is the whole difference from the launch of the CRM card: the answer
 * reaches the person as a signal to the open tab, so the job is queued with the place of launch
 * {@see ClientSummaryOrigin::CallCardTab} and with no chat at all.
 *
 * The queueing is left to a background job. The holder of the lock collects the data of the client and
 * calls the engine ({@see ClientSummaryJobLauncher}), and none of that is read by the caller: the
 * action answers that the generation is running, and the work runs once the answer is gone.
 *
 * Whether the user may read this client and whether the summary is available to them is settled by the
 * caller, before anything is queued: by the time the background job runs there is no authorized user
 * left to ask (R-3).
 *
 * @internal
 */
class CallCardSummaryLaunchService
{
	private const LOGGER_ID = 'crm.aiSummary.callCardLaunch';

	private ClientSummaryJobLauncher $jobLauncher;
	private ClientSummaryBackgroundRunner $background;
	private ClientSummaryReadyNotifier $notifier;

	public function __construct(
		?ClientSummaryJobLauncher $jobLauncher = null,
		?ClientSummaryBackgroundRunner $background = null,
		private ?LoggerInterface $logger = null,
		?ClientSummaryReadyNotifier $notifier = null,
	)
	{
		$this->jobLauncher = $jobLauncher ?? new ClientSummaryJobLauncher();
		$this->background = $background ?? new ClientSummaryBackgroundRunner();
		$this->notifier = $notifier ?? new ClientSummaryReadyNotifier();
	}

	/**
	 * Nothing is answered back: the tab is told that the generation is running by the action itself
	 * (API-02), and every later turn of events reaches it as a signal.
	 *
	 * A background job that never starts is a failure the tab has no other way of learning about, so it
	 * is answered with the same signal a refused queueing is.
	 */
	public function launch(int $userId, ItemIdentifier $client): void
	{
		$job = function () use ($userId, $client): void
		{
			try
			{
				$this->startGeneration($userId, $client);
			}
			catch (\Throwable $exception)
			{
				$this->reportFailure($userId, $client, $exception);
			}
		};

		try
		{
			$this->background->run($job);
		}
		catch (\Throwable $exception)
		{
			$this->reportFailure($userId, $client, $exception);
		}
	}

	/**
	 * The outcome of the queueing is read right here, because it goes nowhere else: the action has
	 * answered long ago, and the tab learns of everything by the signal.
	 *
	 * A job that was created tells of itself: the operation signals the tab when it finishes, however it
	 * finishes, so `Queued` and `AlreadyRunning` say nothing. A refusal is the opposite case - no row of
	 * a job appears on either of those two paths, so neither the operation nor a second reading of the
	 * state would ever mention it, and without a signal here the tab would wait forever.
	 *
	 * Losing the lock is not a refusal. It is answered with `AlreadyRunning`, and the launch that holds
	 * the lock is the one creating the job the tab will hear about.
	 */
	private function startGeneration(int $userId, ItemIdentifier $client): void
	{
		$status = $this->jobLauncher->queue(
			$userId,
			$client->getEntityTypeId(),
			$client->getEntityId(),
			0,
			ClientSummaryOrigin::CallCardTab,
		);

		match ($status)
		{
			ClientSummaryJobStatus::Queued, ClientSummaryJobStatus::AlreadyRunning => null,
			ClientSummaryJobStatus::LimitExceeded => $this->signalState(
				$userId,
				$client,
				ClientSummaryState::LimitExceeded,
			),
			ClientSummaryJobStatus::Failed => $this->signalState($userId, $client, ClientSummaryState::Failed),
		};
	}

	private function reportFailure(int $userId, ItemIdentifier $client, \Throwable $exception): void
	{
		$this->getLogger()->error('the client summary of the call card was not queued', [
			'userId' => $userId,
			'entityTypeId' => $client->getEntityTypeId(),
			'entityId' => $client->getEntityId(),
			'exceptionClass' => $exception::class,
			'exceptionMessage' => $exception->getMessage(),
		]);

		$this->signalState($userId, $client, ClientSummaryState::Failed);
	}

	/**
	 * Tells the tab of a state that no job will ever report, because no job was created (EVENT-01).
	 *
	 * The mark of delivery of the operation does not reach here and does not have to: the outcomes
	 * answered from this method have no row of a job to keep it in, and each of them passes here once.
	 */
	protected function signalState(int $userId, ItemIdentifier $client, ClientSummaryState $state): void
	{
		$this->getLogger()->info('the client summary of the call card ended before the job', [
			'userId' => $userId,
			'entityTypeId' => $client->getEntityTypeId(),
			'entityId' => $client->getEntityId(),
			'state' => $state->value,
		]);

		$this->notifier->notify($userId, $client, $state);
	}

	private function getLogger(): LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}
}
