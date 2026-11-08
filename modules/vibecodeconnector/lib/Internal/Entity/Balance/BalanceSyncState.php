<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\Balance;

final class BalanceSyncState
{
	public function __construct(
		public readonly ?string $balance = null,
		public readonly ?int $version = null,
		public readonly int $requestedVersion = 0,
		public readonly ?int $enqueuedVersion = null,
		public readonly ?int $lastFetchedAt = null,
	)
	{
	}

	public function withRequestedVersion(int $version): self
	{
		return new self(
			balance: $this->balance,
			version: $this->version,
			requestedVersion: max($this->requestedVersion, $version),
			enqueuedVersion: $this->enqueuedVersion,
			lastFetchedAt: $this->lastFetchedAt,
		);
	}

	public function withEnqueuedVersion(int $version): self
	{
		return new self(
			balance: $this->balance,
			version: $this->version,
			requestedVersion: $this->requestedVersion,
			enqueuedVersion: max($this->enqueuedVersion ?? 0, $version),
			lastFetchedAt: $this->lastFetchedAt,
		);
	}

	public function withBalance(string $balance, int $version, int $fetchedAt): self
	{
		return new self(
			balance: $balance,
			version: $version,
			requestedVersion: $this->requestedVersion,
			enqueuedVersion: $this->enqueuedVersion,
			lastFetchedAt: $fetchedAt,
		);
	}
}
