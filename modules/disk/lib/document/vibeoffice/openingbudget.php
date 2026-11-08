<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

final class OpeningBudget
{
	private readonly \Closure $clock;
	private readonly float $deadline;
	private int $remaining;

	public function __construct(
		private readonly int $totalMs,
		?\Closure $clock = null,
		?int $remainingMs = null,
	)
	{
		if ($totalMs < 0)
		{
			throw new \InvalidArgumentException('Opening budget must not be negative.');
		}

		$this->clock = $clock ?? static fn(): float => hrtime(true) / 1e9;
		$this->remaining = max(0, min($totalMs, $remainingMs ?? $totalMs));
		$this->deadline = ($this->clock)() + $this->remaining / 1000;
	}

	/**
	 * Rebuilds the budget a previous request signed into the trusted opening context, spending the
	 * wall-clock time that passed between the two requests. A context without a budget - the state of
	 * every opening until a policy issues one - and a context whose budget cannot be trusted give null.
	 */
	public static function fromTrustedOpening(array $opening, ?\Closure $wallClock = null): ?self
	{
		$budget = $opening['openingBudget'] ?? null;
		$issuedAt = $opening['budgetIssuedAt'] ?? null;
		if (
			!is_array($budget)
			|| ($budget['version'] ?? null) !== 1
			|| !is_int($budget['totalMs'] ?? null)
			|| !is_int($budget['remainingMs'] ?? null)
			|| $budget['totalMs'] < 0
			|| !(is_int($issuedAt) || is_float($issuedAt))
		)
		{
			return null;
		}

		$now = ($wallClock ?? static fn(): float => microtime(true))();
		$elapsedMs = max(0, (int)ceil(($now - (float)$issuedAt) * 1000));

		return new self($budget['totalMs'], remainingMs: max(0, $budget['remainingMs'] - $elapsedMs));
	}

	public function remainingMs(): int
	{
		$this->remaining = max(0, min($this->remaining, (int)floor(($this->deadline - ($this->clock)()) * 1000)));

		return $this->remaining;
	}

	public function deadlineForOpen(): float
	{
		return $this->deadline;
	}

	public function toArray(): array
	{
		return [
			'version' => 1,
			'totalMs' => $this->totalMs,
			'remainingMs' => $this->remainingMs(),
		];
	}
}
