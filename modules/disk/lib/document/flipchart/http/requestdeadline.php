<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Http;

final class RequestDeadline
{
	public const DEFAULT_TIMEOUT_MILLISECONDS = 20_000;

	private readonly float $expiresAt;
	private bool $exceeded = false;

	public function __construct(int $timeoutMilliseconds = self::DEFAULT_TIMEOUT_MILLISECONDS)
	{
		if ($timeoutMilliseconds <= 0)
		{
			throw new \InvalidArgumentException('Request deadline must be positive.');
		}

		$this->expiresAt = microtime(true) + ($timeoutMilliseconds / 1000);
	}

	public function getRemainingMilliseconds(): int
	{
		return (int)ceil($this->getRemainingMicroseconds() / 1000);
	}

	public function getRemainingMicroseconds(): int
	{
		$remaining = (int)ceil(($this->expiresAt - microtime(true)) * 1_000_000);
		if ($remaining <= 0)
		{
			$this->markExceeded();
			throw new \RuntimeException('Board indexing request deadline has been reached.');
		}

		return $remaining;
	}

	public function markExceeded(): void
	{
		$this->exceeded = true;
	}

	public function isExceeded(): bool
	{
		return $this->exceeded || microtime(true) >= $this->expiresAt;
	}
}
