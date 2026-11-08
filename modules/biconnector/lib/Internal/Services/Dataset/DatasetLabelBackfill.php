<?php

namespace Bitrix\BIConnector\Internal\Services\Dataset;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Web\Json;

/**
 * Best-effort historical backfill of physical-dataset descriptions.
 *
 * Triggered from a report-open background job (a live instance the user is working with, so it never
 * wakes an idle Superset). Runs {@see DatasetLabelSync} at most once per hour and only until it
 * succeeds. State lives in the `dataset_labels_backfill` option: `done` stops further runs,
 * `last_attempt_at` throttles retries. Never throws.
 *
 * Gated by {@see Feature::isDatasetLabelBackfillEnabled()}: an instance that does not store the
 * dataset name yet rejects the whole update, so until it is updated the sweep would only produce
 * failed requests. The flag is checked before the state is read, so a disabled sweep leaves no trace
 * and does not consume the retry interval.
 */
final class DatasetLabelBackfill
{
	private const OPTION = 'dataset_labels_backfill';
	private const LOCK_KEY = 'biconnector_dataset_labels_backfill';
	private const RETRY_INTERVAL = 3600;

	/**
	 * Runs the backfill when the flag is on, it is not done yet and the retry interval has elapsed. A DB lock keeps
	 * concurrent report opens from sweeping in parallel. Safe to call from a background job.
	 */
	public static function run(): void
	{
		try
		{
			if (!Feature::isDatasetLabelBackfillEnabled())
			{
				return;
			}

			$state = self::readState();
			if (($state['done'] ?? false) === true)
			{
				return;
			}

			$lastAttemptAt = (int)($state['last_attempt_at'] ?? 0);
			if ($lastAttemptAt > 0 && (time() - $lastAttemptAt) < self::RETRY_INTERVAL)
			{
				return;
			}

			$connection = Application::getConnection();
			if (!$connection->lock(self::LOCK_KEY, 0))
			{
				return;
			}

			try
			{
				$summary = (new DatasetLabelSync())->syncAllPhysicalDatasets();
				Option::set(
					'biconnector',
					self::OPTION,
					Json::encode([
						'last_attempt_at' => time(),
						'done' => $summary['enumerated'] && $summary['failed'] === 0,
					] + $summary),
				);
			}
			finally
			{
				$connection->unlock(self::LOCK_KEY);
			}
		}
		catch (\Throwable $e)
		{
			Logger::logErrors([new Error($e->getMessage())], [
				'message' => 'Dataset label backfill failed',
			]);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function readState(): array
	{
		$raw = Option::get('biconnector', self::OPTION, '');
		if ($raw === '')
		{
			return [];
		}

		try
		{
			$data = Json::decode($raw);
		}
		catch (\Throwable)
		{
			return [];
		}

		return is_array($data) ? $data : [];
	}
}
