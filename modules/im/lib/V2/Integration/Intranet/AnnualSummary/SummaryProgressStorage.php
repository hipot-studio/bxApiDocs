<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Intranet\AnnualSummary;

use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\Date;

/**
 * Keeps the position of the one user a metric has counted halfway.
 *
 * A provider counts users one after another, so it never holds more than one unfinished user and
 * the whole storage is a single record. It lives past the step, not past the season: the period is
 * stamped into the record and checked on reading, and the record expires with the season itself.
 *
 * The record is a step of a background count and not a setting of the module, so it lives in the
 * persistent storage of the portal: a module option is written through a cache the whole portal
 * shares, and one write of it makes the next hit of any user read every option of im from the
 * database anew.
 */
final class SummaryProgressStorage
{
	private const STORAGE_KEY_PREFIX = 'im.annual_summary.progress.';

	private readonly StorageInterface $storage;

	private readonly string $storageKey;

	private readonly string $period;

	private readonly int $ttl;

	public function __construct(
		string $providerId,
		Date $from,
		Date $to,
		?StorageInterface $storage = null,
	)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
		$this->storageKey = self::STORAGE_KEY_PREFIX . $providerId;

		// Both dates are in the key, same as in the cache of the period bounds: a change of the
		// period must not resume a count started over another one.
		$this->period = $from->format('Ymd') . '_' . $to->format('Ymd');

		$this->ttl = SummarySeason::getRemainingSeconds($from);
	}

	public function read(int $userId): ?SummaryProgress
	{
		try
		{
			$record = $this->storage->get($this->storageKey);
		}
		catch (\Throwable)
		{
			// A payload the storage cannot decode is a record to start anew from, not a failed step.
			return null;
		}

		return $this->restoreProgress($record, $userId);
	}

	public function write(SummaryProgress $progress): void
	{
		$this->storage->set(
			$this->storageKey,
			[
				'period' => $this->period,
				'userId' => $progress->userId,
				'chatId' => $progress->chatId,
				'messageId' => $progress->messageId,
				'total' => $progress->total,
				'steps' => $progress->steps,
			],
			$this->ttl,
		);
	}

	public function delete(): void
	{
		$this->storage->delete($this->storageKey);
	}

	/**
	 * The record is untrusted input: one left by another version of the code, by another period or
	 * by another user is a count to start anew, never a total to continue from.
	 */
	private function restoreProgress(mixed $record, int $userId): ?SummaryProgress
	{
		if (!is_array($record))
		{
			return null;
		}

		if (($record['period'] ?? null) !== $this->period || ($record['userId'] ?? null) !== $userId)
		{
			return null;
		}

		$chatId = $record['chatId'] ?? null;
		$messageId = $record['messageId'] ?? null;
		$total = $record['total'] ?? null;
		$steps = $record['steps'] ?? null;

		// Identifiers start at 1 and a written record has at least one step behind it; a total of
		// zero is possible, a negative one is damage.
		if (
			!$this->isIntFrom($chatId, 1)
			|| !$this->isIntFrom($messageId, 1)
			|| !$this->isIntFrom($total, 0)
			|| !$this->isIntFrom($steps, 1)
		)
		{
			return null;
		}

		return new SummaryProgress($userId, $chatId, $messageId, $total, $steps);
	}

	private function isIntFrom(mixed $value, int $min): bool
	{
		return is_int($value) && $value >= $min;
	}
}
