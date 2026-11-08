<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Balance\Storage;

use Bitrix\Main\Web\Json;
use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;
use Bitrix\Vibecodeconnector\Internal\Entity\Balance\BalanceSyncState;
use Bitrix\Vibecodeconnector\Internal\Service\Balance\BalanceValidator;

final class BalanceOptionStateStorage
{
	private const OPTION_NAME = 'vibe_balance_state';
	private const SCHEMA_VERSION = 1;

	public function get(): BalanceSyncState
	{
		$value = (new ModuleOptions())->get(self::OPTION_NAME);
		if ($value === '')
		{
			return new BalanceSyncState();
		}

		try
		{
			$data = Json::decode($value);
			if (!$this->isValidEnvelope($data))
			{
				return new BalanceSyncState();
			}

			return new BalanceSyncState(
				balance: $data['balance'],
				version: $data['version'],
				requestedVersion: $data['requestedVersion'],
				enqueuedVersion: $data['enqueuedVersion'],
				lastFetchedAt: $data['lastFetchedAt'] ?? null,
			);
		}
		catch (\Throwable)
		{
			return new BalanceSyncState();
		}
	}

	public function save(BalanceSyncState $state): void
	{
		$this->writeState($state);
	}

	/** Returns the latest requested state to enqueue, or null when no enqueue is needed. */
	public function markRequested(int $version): ?BalanceSyncState
	{
		$state = $this->get();
		if ($state->version !== null && $version <= $state->version)
		{
			return null;
		}

		$state = $state->withRequestedVersion($version);
		$this->writeState($state);

		return $state->enqueuedVersion === null || $state->requestedVersion > $state->enqueuedVersion
			? $state
			: null
		;
	}

	public function markEnqueued(int $version): BalanceSyncState
	{
		$state = $this->get()->withEnqueuedVersion($version);
		$this->writeState($state);

		return $state;
	}

	public function saveBalance(string $balance, int $version, int $fetchedAt): BalanceSyncState
	{
		$state = $this->get()->withBalance($balance, $version, $fetchedAt);
		$this->writeState($state);

		return $state;
	}

	private function writeState(BalanceSyncState $state): void
	{
		if ($state->balance !== null && !(new BalanceValidator())->isValid($state->balance))
		{
			throw new \InvalidArgumentException('Balance must be a decimal string');
		}

		if ($state->lastFetchedAt !== null && ($state->balance === null || $state->lastFetchedAt <= 0))
		{
			throw new \InvalidArgumentException('Invalid balance fetch time');
		}

		(new ModuleOptions())->set(
			self::OPTION_NAME,
			Json::encode([
				'schemaVersion' => self::SCHEMA_VERSION,
				'balance' => $state->balance,
				'version' => $state->version,
				'requestedVersion' => $state->requestedVersion,
				'enqueuedVersion' => $state->enqueuedVersion,
				'lastFetchedAt' => $state->lastFetchedAt,
			]),
		);
	}

	private function isValidEnvelope(mixed $data): bool
	{
		if (!is_array($data) || ($data['schemaVersion'] ?? null) !== self::SCHEMA_VERSION)
		{
			return false;
		}

		$balance = $data['balance'] ?? null;
		$version = $data['version'] ?? null;
		$requestedVersion = $data['requestedVersion'] ?? null;
		$enqueuedVersion = $data['enqueuedVersion'] ?? null;
		$lastFetchedAt = $data['lastFetchedAt'] ?? null;

		if ($balance !== null && (!is_string($balance) || !(new BalanceValidator())->isValid($balance)))
		{
			return false;
		}

		return ($version === null || (is_int($version) && $version >= 0))
			&& is_int($requestedVersion)
			&& $requestedVersion >= 0
			&& ($enqueuedVersion === null || (is_int($enqueuedVersion) && $enqueuedVersion >= 0))
			&& ($lastFetchedAt === null || (is_int($lastFetchedAt) && $lastFetchedAt > 0))
			&& ($balance !== null || $lastFetchedAt === null);
	}
}
