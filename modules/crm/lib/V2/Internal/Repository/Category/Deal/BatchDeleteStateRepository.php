<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Category\Deal;

use Bitrix\Crm\V2\Internal\Entity\Category\Deal\BatchDeleteState;
use Bitrix\Main\Application;
use Bitrix\Main\Data\LocalStorage\SessionLocalStorage;

class BatchDeleteStateRepository
{
	private const STORAGE_NAME = 'crm_deal_category_batch_delete';
	private const RECORD_ACTIVE = 'active';
	private const RECORD_TERMINAL = 'terminal';
	private const ACTIVE_RECORD_TTL = 3600;
	private const ACTIVE_RECORD_LIMIT = 50;
	private const TERMINAL_RECORD_TTL = 3600;
	private const TERMINAL_RECORD_LIMIT = 50;

	private readonly SessionLocalStorage $storage;
	private readonly \Closure $checkpoint;
	private readonly \Closure $clock;

	public function __construct(
		?SessionLocalStorage $storage = null,
		?\Closure $checkpoint = null,
		?\Closure $clock = null,
	)
	{
		$this->storage = $storage ?? Application::getInstance()->getLocalSession(self::STORAGE_NAME);
		$this->checkpoint = $checkpoint ?? static function(): void {
			Application::getInstance()->getSessionLocalStorageManager()->save();
		};
		$this->clock = $clock ?? static fn(): int => time();
	}

	public function has(string $token): bool
	{
		$this->cleanupRecords();

		return isset($this->storage[$token]);
	}

	public function getOwnerUserId(string $token): ?int
	{
		$record = $this->getRecord($token);
		$ownerUserId = $record['ownerUserId'] ?? null;

		return is_int($ownerUserId) && $ownerUserId > 0 ? $ownerUserId : null;
	}

	public function isTerminal(string $token): bool
	{
		return ($this->getRecord($token)['kind'] ?? null) === self::RECORD_TERMINAL;
	}

	public function getTerminalPayload(string $token): ?array
	{
		$record = $this->getRecord($token);
		$payload = $record['payload'] ?? null;

		return ($record['kind'] ?? null) === self::RECORD_TERMINAL && is_array($payload)
			? $payload
			: null
		;
	}

	public function load(string $token): ?BatchDeleteState
	{
		$record = $this->getRecord($token);
		if (($record['kind'] ?? null) !== self::RECORD_ACTIVE || !is_array($record['state'] ?? null))
		{
			return null;
		}

		$state = BatchDeleteState::fromArray($record['state']);
		if ($state === null || $state->getOwnerUserId() !== ($record['ownerUserId'] ?? null))
		{
			return null;
		}

		return $state;
	}

	public function save(string $token, BatchDeleteState $state): void
	{
		$this->storage->set($token, [
			'kind' => self::RECORD_ACTIVE,
			'ownerUserId' => $state->getOwnerUserId(),
			'state' => $state->toArray(),
			'updatedAt' => ($this->clock)(),
		]);
		$this->cleanupRecords();
		($this->checkpoint)();
	}

	public function markTerminal(string $token, int $ownerUserId, array $payload): void
	{
		$this->storage->set($token, [
			'kind' => self::RECORD_TERMINAL,
			'ownerUserId' => $ownerUserId,
			'payload' => $payload,
			'completedAt' => ($this->clock)(),
		]);
		$this->cleanupRecords();
		($this->checkpoint)();
	}

	private function getRecord(string $token): array
	{
		$this->cleanupRecords();
		if (!isset($this->storage[$token]))
		{
			return [];
		}

		$record = $this->storage[$token];

		return is_array($record) ? $record : [];
	}

	private function cleanupRecords(): void
	{
		$now = ($this->clock)();
		$activeRecords = [];
		$terminalRecords = [];
		$hasChanges = false;
		foreach ($this->storage as $token => $record)
		{
			if (!is_array($record))
			{
				continue;
			}
			if (($record['kind'] ?? null) === self::RECORD_ACTIVE)
			{
				$updatedAt = $record['updatedAt'] ?? null;
				if (!is_int($updatedAt) || $updatedAt + self::ACTIVE_RECORD_TTL <= $now)
				{
					unset($this->storage[$token]);
					$hasChanges = true;

					continue;
				}

				$activeRecords[$token] = $updatedAt;

				continue;
			}
			if (($record['kind'] ?? null) !== self::RECORD_TERMINAL)
			{
				continue;
			}

			$completedAt = $record['completedAt'] ?? null;
			if (!is_int($completedAt) || $completedAt + self::TERMINAL_RECORD_TTL <= $now)
			{
				unset($this->storage[$token]);
				$hasChanges = true;

				continue;
			}

			$terminalRecords[$token] = $completedAt;
		}

		$hasChanges = $this->applyRecordLimit($activeRecords, self::ACTIVE_RECORD_LIMIT) || $hasChanges;
		$hasChanges = $this->applyRecordLimit($terminalRecords, self::TERMINAL_RECORD_LIMIT) || $hasChanges;

		if ($hasChanges)
		{
			($this->checkpoint)();
		}
	}

	private function applyRecordLimit(array $records, int $limit): bool
	{
		asort($records, SORT_NUMERIC);
		$recordsToRemove = count($records) - $limit;
		if ($recordsToRemove > 0)
		{
			foreach (array_slice(array_keys($records), 0, $recordsToRemove) as $token)
			{
				unset($this->storage[$token]);
			}

			return true;
		}

		return false;
	}
}
