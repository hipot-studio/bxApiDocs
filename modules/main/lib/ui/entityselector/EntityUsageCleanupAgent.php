<?php

namespace Bitrix\Main\UI\EntitySelector;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\Callback;

/**
 * Applies the retention rules of b_entity_usage: first deletes the expired rows,
 * then walks the users by a cursor and trims the entity types of a pair of a user and a context holding more rows
 * than the limit.
 */
final class EntityUsageCleanupAgent
{
	public const CURSOR_OPTION = 'entity_usage_cleanup_cursor';
	public const CURSOR_STORAGE_KEY = 'main.entity_usage.cleanup_cursor';
	public const CURSOR_TTL = 604800;
	public const TIME_BUDGET = 10.0;
	public const RETRY_PERIOD = 60;
	public const USER_BATCH = 100;
	/** The server time of the daily run, also used by the registration in InstallDB. */
	public const NIGHT_SLOT = '04:35:00';

	private float $startedAt = 0.0;
	private readonly StorageInterface $storage;

	public function __construct(
		private readonly EntityUsageRetention $retention = new EntityUsageRetention(),
		private readonly float $timeBudget = self::TIME_BUDGET,
		?StorageInterface $storage = null,
	)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	/**
	 * Runs one cleanup step and schedules the next invocation.
	 *
	 * @return Callback
	 */
	public static function run(): Callback
	{
		self::applyRetryPeriod((new self())->step());

		return new Callback([self::class, __FUNCTION__]);
	}

	/**
	 * Runs the rules until the work or the time budget is over.
	 * At least one portion is deleted, or one batch of users is walked, whatever the budget is.
	 *
	 * @return bool True when some work is left for the next run.
	 */
	public function step(): bool
	{
		$this->startedAt = microtime(true);

		while ($this->retention->deleteExpiredPortion() > 0)
		{
			if ($this->isOutOfTime())
			{
				return true;
			}
		}

		[$savedCursor, $hasSavedCursor] = $this->loadCursor();

		// the rule of the limit is switched off: there is nothing to trim, the walk is over
		if ($this->retention->getContextLimit() === 0)
		{
			$this->resetCursor($hasSavedCursor);

			return false;
		}

		$cursor = $savedCursor;
		while (true)
		{
			$batch = $this->retention->findOverflowingContexts($cursor, self::USER_BATCH);
			if ($batch['lastUserId'] === null)
			{
				$this->resetCursor($hasSavedCursor);

				return false;
			}

			foreach ($batch['pairs'] as $pair)
			{
				foreach ($this->retention->trimContext($pair['USER_ID'], $pair['CONTEXT'], $pair['ENTITY_ID']) as $deleted)
				{
					// the cursor stays before the unfinished batch, so the batch is walked again
					if ($this->isOutOfTime())
					{
						$this->saveCursor($cursor, $savedCursor);

						return true;
					}
				}
			}

			$cursor = $batch['lastUserId'];

			if ($this->isOutOfTime())
			{
				$this->saveCursor($cursor, $savedCursor);

				return true;
			}
		}
	}

	private function loadCursor(): array
	{
		$stored = $this->storage->get(self::CURSOR_STORAGE_KEY);
		$legacy = Option::get('main', self::CURSOR_OPTION, '');

		if ($stored !== null)
		{
			if ($legacy !== '')
			{
				Option::delete('main', ['name' => self::CURSOR_OPTION]);
			}

			return [$this->normalizeCursor($stored), true];
		}

		$legacyCursor = $this->normalizeCursor($legacy);
		if ($legacyCursor > 0)
		{
			$this->storeCursor($legacyCursor);
			Option::delete('main', ['name' => self::CURSOR_OPTION]);

			return [$legacyCursor, true];
		}

		return [0, false];
	}

	private function normalizeCursor(mixed $value): int
	{
		if (!is_int($value) && !is_string($value))
		{
			return 0;
		}

		$cursor = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

		return $cursor === false ? 0 : $cursor;
	}

	private function storeCursor(int $cursor): void
	{
		if (!$this->storage->set(self::CURSOR_STORAGE_KEY, $cursor, self::CURSOR_TTL))
		{
			throw new \RuntimeException('Could not save entity usage cleanup cursor');
		}
	}

	private function saveCursor(int $cursor, int $savedCursor): void
	{
		if ($cursor !== $savedCursor)
		{
			$this->storeCursor($cursor);
		}
	}

	private function resetCursor(bool $hasSavedCursor): void
	{
		if (!$hasSavedCursor)
		{
			return;
		}

		$this->storeCursor(0);
		if (Option::get('main', self::CURSOR_OPTION, '') !== '')
		{
			Option::delete('main', ['name' => self::CURSOR_OPTION]);
		}
		if (!$this->storage->delete(self::CURSOR_STORAGE_KEY))
		{
			throw new \RuntimeException('Could not delete entity usage cleanup cursor');
		}
	}

	/**
	 * The agent is not periodic: its next run is the current time plus the period.
	 * A finished walk is moved back to the night slot, so the catch-up runs do not shift the daily run.
	 */
	private static function applyRetryPeriod(bool $hasWork): void
	{
		global $pPERIOD;
		$pPERIOD = $hasWork ? self::RETRY_PERIOD : self::getSecondsToNightSlot(time());
	}

	/**
	 * Returns the seconds from $now to the next night slot of the server time.
	 * The slot is taken from the calendar, so on the day the clocks go back it can be up to 25 hours away.
	 *
	 * @param int $now Unix timestamp.
	 * @return int
	 */
	public static function getSecondsToNightSlot(int $now): int
	{
		$slot = strtotime(self::NIGHT_SLOT, $now);
		if ($slot <= $now)
		{
			$slot = strtotime('tomorrow ' . self::NIGHT_SLOT, $now);
		}

		return $slot - $now;
	}

	private function isOutOfTime(): bool
	{
		return microtime(true) - $this->startedAt >= $this->timeBudget;
	}
}
