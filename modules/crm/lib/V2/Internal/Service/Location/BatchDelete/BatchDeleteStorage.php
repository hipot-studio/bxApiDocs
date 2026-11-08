<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Location\BatchDelete;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Data\LocalStorage\SessionLocalStorage;
use Bitrix\Main\Data\LocalStorage\SessionLocalStorageManager;
use Bitrix\Main\Data\LocalStorage\Storage\CacheEngineInterface;
use Bitrix\Main\Data\LocalStorage\Storage\CacheStorage;
use Bitrix\Main\Data\LocalStorage\Storage\NativeSessionStorage;
use Bitrix\Main\Data\LocalStorage\Storage\StorageInterface;
use Closure;

final class BatchDeleteStorage
{
	private const STORAGE_NAME = 'crm_location_batch_delete';
	private const ACTIVE_MARKER = 'activeMarker';
	private const STATES = 'states';
	private const RECEIPTS = 'receipts';

	private readonly SessionLocalStorage $storage;
	private readonly Closure $flush;
	private readonly Closure $checkpoint;

	public function __construct(
		?SessionLocalStorage $storage = null,
		?Closure $flush = null,
		?Closure $checkpoint = null,
	)
	{
		if ($storage !== null && $flush !== null)
		{
			$this->storage = $storage;
			$this->flush = $flush;
			$this->checkpoint = $checkpoint ?? $flush;

			return;
		}

		$manager = Application::getInstance()->getSessionLocalStorageManager();
		$usesManagerStorage = $storage === null;
		$this->storage = $storage ?? $manager->get(self::STORAGE_NAME);
		$this->flush = $flush ?? static fn() => $manager->save();
		$this->checkpoint = $checkpoint
			?? ($usesManagerStorage ? $this->createCheckpoint($manager) : $this->flush)
		;
	}

	public function loadState(string $token): ?BatchDeleteState
	{
		$data = $this->storage->getData()[self::STATES][$token] ?? null;
		$state = is_array($data) ? BatchDeleteState::fromArray($data) : null;

		return $state?->token === $token ? $state : null;
	}

	public function loadCompleted(string $token): ?BatchDeleteCompletedReceipt
	{
		$data = $this->storage->getData()[self::RECEIPTS][$token] ?? null;
		$receipt = is_array($data) ? BatchDeleteCompletedReceipt::fromArray($data) : null;

		return $receipt?->token === $token ? $receipt : null;
	}

	public function getActiveMarker(): ?array
	{
		$marker = $this->storage->getData()[self::ACTIVE_MARKER] ?? null;
		if (
			!is_array($marker)
			|| ($marker['gridId'] ?? null) !== BatchDeleteState::GRID_ID
			|| !is_string($marker['token'] ?? null)
		)
		{
			return null;
		}

		return [
			'gridId' => BatchDeleteState::GRID_ID,
			'token' => $marker['token'],
			'leaseExpiresAt' => max(0, (int)($marker['leaseExpiresAt'] ?? 0)),
		];
	}

	public function saveState(BatchDeleteState $state): void
	{
		$this->storeState($state);
		$this->flush();
	}

	public function checkpointState(BatchDeleteState $state): void
	{
		$this->storeState($state);
		($this->checkpoint)();
	}

	private function storeState(BatchDeleteState $state): void
	{
		$states = $this->storage->getData()[self::STATES] ?? [];
		$states[$state->token] = $state->toArray();
		$this->storage[self::STATES] = $states;
		$this->storage[self::ACTIVE_MARKER] = [
			'gridId' => BatchDeleteState::GRID_ID,
			'token' => $state->token,
			'leaseExpiresAt' => $state->leaseExpiresAt,
		];
	}

	public function complete(BatchDeleteState $state, BatchDeleteCompletedReceipt $receipt): void
	{
		$receipts = $this->storage->getData()[self::RECEIPTS] ?? [];
		$receipts[$receipt->token] = $receipt->toArray();
		$this->storage[self::RECEIPTS] = $receipts;

		$states = $this->storage->getData()[self::STATES] ?? [];
		unset($states[$state->token]);
		$this->storage[self::STATES] = $states;

		$marker = $this->getActiveMarker();
		if (($marker['token'] ?? null) === $state->token)
		{
			unset($this->storage[self::ACTIVE_MARKER]);
		}

		$this->flush();
	}

	public function clearActiveMarker(): void
	{
		unset($this->storage[self::ACTIVE_MARKER]);
		$this->flush();
	}

	public function purgeExpiredReceipts(int $now): void
	{
		$receipts = $this->storage->getData()[self::RECEIPTS] ?? [];
		$changed = false;
		foreach ($receipts as $token => $data)
		{
			$receipt = is_array($data) ? BatchDeleteCompletedReceipt::fromArray($data) : null;
			if ($receipt === null || $receipt->isExpired($now))
			{
				unset($receipts[$token]);
				$changed = true;
			}
		}

		if ($changed)
		{
			$this->storage[self::RECEIPTS] = $receipts;
			$this->flush();
		}
	}

	private function flush(): void
	{
		($this->flush)();
	}

	private function createCheckpoint(SessionLocalStorageManager $manager): Closure
	{
		$physicalStorage = $this->createPhysicalStorage();

		return function () use ($manager, $physicalStorage): void {
			$physicalStorage->write(
				$this->storage->getUniqueName(),
				$this->storage->getData(),
				$manager->getTtl(),
			);
		};
	}

	private function createPhysicalStorage(): StorageInterface
	{
		$cacheEngine = Cache::createCacheEngine();
		if ($cacheEngine instanceof CacheEngineInterface)
		{
			return new CacheStorage($cacheEngine);
		}

		return new NativeSessionStorage(Application::getInstance()->getSession());
	}
}
