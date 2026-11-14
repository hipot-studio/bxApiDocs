<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Metrics;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Repository\Sync\SyncMetricRepository;
use Bitrix\Disk\Internal\Repository\Sync\SyncSessionRepository;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\SyncFeatureOption;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Measures one call of the sync contour and records it after the answer has gone.
 *
 * The call itself pays for a clock reading and for queueing one background job; the last read of the pair,
 * the size of a downloaded file and the aggregate are written by that job, and the job pays no clock reading
 * of its own: the rows of the call go by one statement into the hour of the database. A job that fails
 * costs the answer nothing: the call is counted as lost and reported to the log of the contour.
 */
final class CallMetrics
{
	public const OPTION_NAME = 'disk_sync_metrics_enabled';

	public const NAME_CALL = 'call';
	public const LABEL_METRICS_WRITE = 'metrics.write';
	public const OUTCOME_OK = 'ok';

	private const NAME_LOSS = 'loss';
	private const OUTCOME_MAX_LENGTH = 48;
	private const LOGGER_CHANNEL = 'sync';

	private ?int $startedAt = null;
	private ?string $revisedOutcome = null;

	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly SyncMetricRepository $metricRepository = new SyncMetricRepository(),
		private readonly SyncSessionRepository $sessionRepository = new SyncSessionRepository(),
		private readonly SyncFeatureOption $featureOption = new SyncFeatureOption(),
		?LoggerInterface $logger = null,
	)
	{
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'metrics');
	}

	public function start(): void
	{
		$this->startedAt = hrtime(true);
	}

	/**
	 * Only the first finish after a start counts, so a call is measured once whichever path it ends on.
	 * Nothing here may fail the call being measured.
	 */
	public function finish(
		string $method,
		string $outcome,
		?int $userId = null,
		?string $appId = null,
		bool $readsPair = false,
		?int $downloadedObjectId = null,
	): void
	{
		if ($this->startedAt === null)
		{
			return;
		}

		$elapsedMs = (hrtime(true) - $this->startedAt) / 1e6;
		$this->startedAt = null;
		$this->revisedOutcome = null;

		try
		{
			if (!$this->isEnabled())
			{
				return;
			}

			Application::getInstance()->addBackgroundJob(
				fn() => $this->write(
					$method,
					self::boundOutcome($this->revisedOutcome ?? $outcome),
					$elapsedMs,
					(int)$userId,
					(string)$appId,
					$readsPair && $this->revisedOutcome === null,
					$this->revisedOutcome === null ? $downloadedObjectId : null,
				),
			);
		}
		catch (Throwable $error)
		{
			$this->logger->error('Call of the sync contour was not scheduled for recording: {message}', [
				'message' => $error->getMessage(),
				'method' => $method,
				'exception' => $error,
			]);
		}
	}

	/**
	 * The call finished last ended otherwise than its finish said: it is recorded with this outcome, as
	 * neither a read of the pair nor a download. A call whose end is known only after its time has been
	 * taken - a stream that ends the process when it succeeds - is finished as a success and revised
	 * when it fails. Takes effect until the background job of the call has run.
	 */
	public function reviseOutcome(string $outcome): void
	{
		$this->revisedOutcome = $outcome;
	}

	private function isEnabled(): bool
	{
		return
			$this->featureOption->isEnabled()
			&& Option::get(Driver::INTERNAL_MODULE_ID, self::OPTION_NAME, 'Y') === 'Y'
		;
	}

	private function write(
		string $method,
		string $outcome,
		float $elapsedMs,
		int $userId,
		string $appId,
		bool $readsPair,
		?int $downloadedObjectId,
	): void
	{
		$jobStartedAt = hrtime(true);
		$touchError = null;

		try
		{
			$sumBytes = $downloadedObjectId === null ? 0 : $this->sizeOfObject($downloadedObjectId);

			if ($readsPair && $userId > 0 && $appId !== '')
			{
				try
				{
					$this->sessionRepository->touchLastRead($userId, $appId);
				}
				catch (Throwable $error)
				{
					$touchError = $error;
				}
			}

			$rows = [
				[
					'name' => self::NAME_CALL,
					'label' => $method,
					'appId' => $appId,
					'outcome' => $outcome,
					'bucketMs' => LatencyBucket::of($elapsedMs),
					'sumMs' => (int)round($elapsedMs),
					'sumBytes' => $sumBytes,
				],
				$touchError === null ? self::ownTimeRow($jobStartedAt) : self::lossRow($method),
			];

			$this->metricRepository->incrementInCurrentHour($rows);
		}
		catch (Throwable $error)
		{
			$this->metricRepository->recordLossInCurrentHour($method);
			$this->logNotRecorded($error, $method, $outcome, $appId);

			return;
		}

		if ($touchError !== null)
		{
			$this->logNotRecorded($touchError, $method, $outcome, $appId);

			return;
		}

		$this->logger->debug('Call of the sync contour recorded', [
			'method' => $method,
			'outcome' => $outcome,
			'appId' => $appId,
			'ms' => round($elapsedMs, 3),
		]);
	}

	/**
	 * The own time of the job is taken before its statement: the price of the statement is not in it.
	 */
	private static function ownTimeRow(int $jobStartedAt): array
	{
		$writeMs = (hrtime(true) - $jobStartedAt) / 1e6;

		return [
			'name' => self::NAME_CALL,
			'label' => self::LABEL_METRICS_WRITE,
			'appId' => '',
			'outcome' => self::OUTCOME_OK,
			'bucketMs' => LatencyBucket::of($writeMs),
			'sumMs' => (int)round($writeMs),
		];
	}

	private static function lossRow(string $method): array
	{
		return [
			'name' => self::NAME_LOSS,
			'label' => $method,
			'appId' => '',
			'outcome' => '',
			'bucketMs' => 0,
		];
	}

	private function logNotRecorded(Throwable $error, string $method, string $outcome, string $appId): void
	{
		$this->logger->error('Call of the sync contour was not recorded: {message}', [
			'message' => $error->getMessage(),
			'method' => $method,
			'outcome' => $outcome,
			'appId' => $appId,
			'exception' => $error,
		]);
	}

	private function sizeOfObject(int $objectId): int
	{
		$row = ObjectTable::query()
			->setSelect(['SIZE'])
			->where('ID', $objectId)
			->fetch()
		;

		return (int)($row['SIZE'] ?? 0);
	}

	/**
	 * Codes of the platform are derived from class names and may outgrow the column; the tail keeps the
	 * name of the class, which is the part that tells them apart.
	 */
	private static function boundOutcome(string $outcome): string
	{
		return strlen($outcome) > self::OUTCOME_MAX_LENGTH
			? substr($outcome, -self::OUTCOME_MAX_LENGTH)
			: $outcome
		;
	}
}
