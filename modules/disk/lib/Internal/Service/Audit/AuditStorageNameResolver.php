<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Storage;
use Closure;
use Throwable;

/**
 * Names the disks a move went between. The event keeps only their ids, so the names are read
 * when the card is opened; a disk that is gone is left out, and the card shows its id.
 */
final class AuditStorageNameResolver
{
	private const STORAGE_ID_KEYS = ['oldStorageId', 'newStorageId'];

	private readonly Closure $nameLoader;

	public function __construct(?callable $nameLoader = null)
	{
		$this->nameLoader = Closure::fromCallable(
			$nameLoader ?? static fn(int $storageId): ?string => Storage::loadById($storageId)?->getName(),
		);
	}

	/**
	 * @param array<string, bool|float|int|string|null> $details
	 * @return array<string, string>
	 */
	public function resolve(array $details): array
	{
		$names = [];
		foreach (self::STORAGE_ID_KEYS as $key)
		{
			$storageId = $details[$key] ?? null;
			if (!is_int($storageId) || $storageId <= 0 || isset($names[(string)$storageId]))
			{
				continue;
			}

			$name = $this->loadName($storageId);
			if ($name !== null)
			{
				$names[(string)$storageId] = $name;
			}
		}

		return $names;
	}

	private function loadName(int $storageId): ?string
	{
		try
		{
			$name = trim((string)($this->nameLoader)($storageId));
		}
		catch (Throwable)
		{
			return null;
		}

		return $name === '' ? null : $name;
	}
}
