<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Save;

use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Psr\Log\LoggerInterface;

/**
 * Says that a final save of a document key is being processed right now.
 *
 * Without it a save which takes longer than the two minutes of inactivity looks abandoned to the next
 * opening: the portal hands out a new key on the content it already has, and the version the webhook is
 * still writing is overwritten by the session which started on the stale document. The mark covers one
 * phase of the save at a time - it is set again before the version is written - so neither phase has to
 * fit into the time the other one spent. The storage is never allowed to break the save: a portal which
 * cannot keep the mark still writes the version and still answers the callback.
 */
final class SavingMarker
{
	public const KEY_PREFIX = 'disk.onlyoffice.saving.';

	/**
	 * Seconds of wall clock the writing of the version gets on top of the limit of the download.
	 *
	 * The version of a document of up to a hundred megabytes goes into a cloud storage, and nothing
	 * bounds that by the clock, so the reserve is what the mark bets on. The bet is kept cheap by
	 * setting the mark again right before the writing: the whole lifetime then covers the writing alone.
	 *
	 * Both halves of the lifetime are wall clock on purpose. The row of the mark expires by the clock of
	 * the database, while the limit of execution of the webhook counts processor time and stops for none
	 * of the waiting - for the editor server, for the disk, for the database - which is what this save
	 * is almost entirely made of.
	 */
	private const WRITE_WALL_CLOCK_RESERVE = 120;

	private readonly StorageInterface $storage;
	private readonly LoggerInterface $logger;
	private readonly int $ttl;

	public function __construct(
		?StorageInterface $storage = null,
		?LoggerInterface $logger = null,
		?SaveTimingConfig $timingConfig = null,
	)
	{
		$locator = ServiceLocator::getInstance();
		$this->storage = $storage ?? $locator->get(PersistentStorageInterface::class);
		$this->logger = $logger ?? $locator->get(DocumentSaveService::LOGGER_SERVICE_ID);
		$timingConfig ??= $locator->get(SaveTimingConfig::class);
		$this->ttl = $timingConfig->downloadLimit + self::WRITE_WALL_CLOCK_RESERVE;
	}

	/**
	 * Seconds one phase of the save is marked for; the wall-clock limits of the portal decide it.
	 */
	public function getTtl(): int
	{
		return $this->ttl;
	}

	/**
	 * Marks the key, or marks it anew for the next phase of the same save.
	 *
	 * The deadline is written into the mark as well, because only part of the storage refuses to answer
	 * about a row whose time is up, while the rows themselves are swept once a day.
	 *
	 * @return bool whether the mark was stored - only then is there anything to remove afterwards.
	 */
	public function trySet(string $documentSessionHash): bool
	{
		try
		{
			return $this->storage->set(
				$this->makeKey($documentSessionHash),
				time() + $this->ttl,
				$this->ttl,
			);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure('set', $exception);

			return false;
		}
	}

	public function tryDelete(string $documentSessionHash): bool
	{
		try
		{
			return $this->storage->delete($this->makeKey($documentSessionHash));
		}
		catch (\Throwable $exception)
		{
			$this->logFailure('delete', $exception);

			return false;
		}
	}

	/**
	 * A storage which cannot answer is treated as holding no mark: the check only ever adds a reason to
	 * keep the key, and losing it returns the behaviour the portal had before the mark existed.
	 */
	public function isActive(string $documentSessionHash): bool
	{
		try
		{
			return $this->isDeadlineAhead($this->storage->get($this->makeKey($documentSessionHash)));
		}
		catch (\Throwable $exception)
		{
			$this->logFailure('check', $exception);

			return false;
		}
	}

	/**
	 * The same question about a whole portion of keys, asked of the storage once.
	 *
	 * A storage which cannot answer is treated as holding no mark, exactly as the single check is.
	 *
	 * @param string[] $documentSessionHashes
	 * @return string[] those of the given keys which are being saved right now.
	 */
	public function findActive(array $documentSessionHashes): array
	{
		$hashByKey = [];
		foreach ($documentSessionHashes as $documentSessionHash)
		{
			$hashByKey[$this->makeKey($documentSessionHash)] = $documentSessionHash;
		}

		if (empty($hashByKey))
		{
			return [];
		}

		try
		{
			$values = $this->storage->getMultiple(array_keys($hashByKey));
		}
		catch (\Throwable $exception)
		{
			$this->logFailure('read', $exception);

			return [];
		}

		$active = [];
		foreach ($values as $key => $value)
		{
			if (isset($hashByKey[$key]) && $this->isDeadlineAhead($value))
			{
				$active[] = $hashByKey[$key];
			}
		}

		return $active;
	}

	/**
	 * A batch read of the storage answers about every row which is physically there, expired or not, and
	 * a mark of a webhook killed before it could release its own outlives that webhook. Reading the
	 * deadline out of the mark is what makes one key and a portion of keys answer the same way.
	 */
	private function isDeadlineAhead(mixed $value): bool
	{
		return is_numeric($value) && (int)$value > time();
	}

	private function makeKey(string $documentSessionHash): string
	{
		return self::KEY_PREFIX . $documentSessionHash;
	}

	/**
	 * The key of the document is a secret of the session and never reaches the log; the action and the
	 * exception are enough to tell a broken storage from a save which failed on its own.
	 */
	private function logFailure(string $action, \Throwable $exception): void
	{
		$this->logger->warning('OnlyOffice save: the saving mark could not be {action}. {exception}: {message}', [
			'action' => $action,
			'exception' => $exception::class,
			'message' => $exception->getMessage(),
		]);
	}
}
