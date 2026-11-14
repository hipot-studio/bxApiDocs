<?php
declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Agent\Sync;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Infrastructure\Agent\BaseDiskAgent;
use Bitrix\Disk\Infrastructure\Agent\RunResult;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\Metrics\CallMetrics;
use Bitrix\Disk\Internal\Service\Sync\Metrics\MetricsSnapshot;
use Bitrix\Disk\Internal\Service\Sync\SyncFeatureOption;
use Bitrix\Main\Config\Option;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The regular tick behind {@see MetricsSnapshot}. Idle while the contour or its metrics are switched off,
 * so a portal without the contour pays nothing for it.
 */
final class MetricsSnapshotAgent extends BaseDiskAgent
{
	private const LOGGER_CHANNEL = 'sync';

	private Closure $take;
	private SyncFeatureOption $feature;
	private LoggerInterface $logger;

	/**
	 * @param (callable(): array)|null $take the snapshot itself
	 */
	public function __construct(
		?callable $take = null,
		?SyncFeatureOption $feature = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->take = $take === null
			? static fn (): array => (new MetricsSnapshot())->take()
			: Closure::fromCallable($take);
		$this->feature = $feature ?? new SyncFeatureOption();
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'metrics');
	}

	/**
	 * Always a retry: an empty answer would remove the agent from the portal for good.
	 */
	protected function runInternal(): RunResult
	{
		if (!$this->isActive())
		{
			return new RunResult(true);
		}

		try
		{
			($this->take)();
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'the snapshot of the metrics of the contour did not finish: {message}',
				['message' => $exception->getMessage(), 'exception' => $exception],
			);
		}

		return new RunResult(true);
	}

	private function isActive(): bool
	{
		return
			$this->feature->isEnabled()
			&& Option::get(Driver::INTERNAL_MODULE_ID, CallMetrics::OPTION_NAME, 'Y') === 'Y'
		;
	}
}
