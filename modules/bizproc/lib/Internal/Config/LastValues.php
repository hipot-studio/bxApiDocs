<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Config;

use Bitrix\Bizproc\Public\Feature\Template\LastValuesCaptureFlag;
use Bitrix\Main\Config\Option;

/**
 * The only place where the last-run values options are read: capture, reading and the editor
 * feature code are all derived from these getters.
 */
class LastValues
{
	private const MODULE_ID = 'bizproc';

	private const THROTTLE_OPTION = 'last_values_throttle';
	private const VALUE_LIMIT_OPTION = 'last_values_value_limit';
	private const SNAPSHOT_LIMIT_OPTION = 'last_values_snapshot_limit';
	private const KEY_LIMIT_OPTION = 'last_values_key_limit';
	private const RETENTION_OPTION = 'last_values_retention';

	private const DEFAULT_THROTTLE_INTERVAL = 3600;
	private const DEFAULT_VALUE_SIZE_LIMIT = 1024;
	private const DEFAULT_SNAPSHOT_SIZE_LIMIT = 32768;
	private const DEFAULT_KEY_LIMIT = 500;
	private const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Capacity of the VALUES_DATA column of the snapshot: TEXT holds 65535 bytes in MySQL, and a limit
	 * above it would only let the write fail or be cut down by the database.
	 */
	private const MAX_SNAPSHOT_SIZE_LIMIT = 65535;

	public function isCaptureEnabled(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(LastValuesCaptureFlag::class);
	}

	/**
	 * Seconds between two captures of the same template version; 0 captures every finished run.
	 */
	public function getThrottleInterval(): int
	{
		return $this->getIntOption(self::THROTTLE_OPTION, self::DEFAULT_THROTTLE_INTERVAL, 0);
	}

	/**
	 * Bytes allowed for a single captured value: a single value never gets more room than the whole
	 * snapshot, whatever the options say.
	 *
	 * A scalar is cut to the limit, a collection is closed on the item that no longer fits. For a
	 * collection of document identifiers the limit is spent on item boundaries only: an identifier cut
	 * in half addresses no document, so the first one is kept whole even when it alone is over the
	 * limit. The bound that always holds is the snapshot one - an entry that does not fit the snapshot
	 * is dropped by {@see \Bitrix\Bizproc\Internal\Service\LastValues\ValuesCollector::shrink()}.
	 */
	public function getValueSizeLimit(): int
	{
		return min(
			$this->getIntOption(self::VALUE_LIMIT_OPTION, self::DEFAULT_VALUE_SIZE_LIMIT, 1),
			$this->getSnapshotSizeLimit(),
		);
	}

	/**
	 * Bytes allowed for the whole encoded snapshot, never above the capacity of the storage column.
	 */
	public function getSnapshotSizeLimit(): int
	{
		return $this->getIntOption(
			self::SNAPSHOT_LIMIT_OPTION,
			self::DEFAULT_SNAPSHOT_SIZE_LIMIT,
			1,
			self::MAX_SNAPSHOT_SIZE_LIMIT,
		);
	}

	public function getKeyLimit(): int
	{
		return $this->getIntOption(self::KEY_LIMIT_OPTION, self::DEFAULT_KEY_LIMIT, 1);
	}

	public function getRetentionDays(): int
	{
		return $this->getIntOption(self::RETENTION_OPTION, self::DEFAULT_RETENTION_DAYS, 1);
	}

	/**
	 * A non-numeric or out-of-range override would silently disable a safeguard, so such values
	 * fall back to the default instead of being clamped.
	 */
	private function getIntOption(string $option, int $default, int $minValue, ?int $maxValue = null): int
	{
		$stored = Option::get(self::MODULE_ID, $option, (string)$default);
		if (!is_numeric($stored))
		{
			return $default;
		}

		$value = (int)$stored;
		$isInRange = $value >= $minValue && ($maxValue === null || $value <= $maxValue);

		return $isInRange ? $value : $default;
	}
}
