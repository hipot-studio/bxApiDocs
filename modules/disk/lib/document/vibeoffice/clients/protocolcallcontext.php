<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Clients;

/**
 * Counts calls inside one logical platform operation without retaining a target.
 */
final class ProtocolCallContext
{
	private ?string $operation = null;
	/** @var array<string, int> */
	private array $attempts = [];

	public function beginOperation(string $method): void
	{
		$this->operation = $method;
		$this->attempts = [];
	}

	public function endOperation(): void
	{
		$this->operation = null;
		$this->attempts = [];
	}

	public function beginOpenAttempt(): int
	{
		if ($this->operation !== 'open')
		{
			return 1;
		}

		return $this->attempts['open'] = ($this->attempts['open'] ?? 0) + 1;
	}

	public function currentOpenAttempt(): int
	{
		return $this->operation === 'open' ? max(1, $this->attempts['open'] ?? 0) : 1;
	}

	public function nextAttempt(string $method): int
	{
		if ($this->operation !== $method)
		{
			return 1;
		}

		return $this->attempts[$method] = ($this->attempts[$method] ?? 0) + 1;
	}
}
