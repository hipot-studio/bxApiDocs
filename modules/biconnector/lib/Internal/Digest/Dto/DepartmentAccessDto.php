<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Dto;

/**
 * Report view right of a department taken as a whole.
 *
 * A full answer given by a matching role relation is never counted: its
 * counters stay zero, since nobody was looked at to give it.
 */
final class DepartmentAccessDto
{
	public const STATE_FULL = 'full';
	public const STATE_PARTIAL = 'partial';
	public const STATE_NONE = 'none';

	private function __construct(
		public readonly int $nodeId,
		public readonly string $state,
		public readonly int $withoutAccess,
		public readonly int $total,
	)
	{
	}

	public static function full(int $nodeId): self
	{
		return new self($nodeId, self::STATE_FULL, 0, 0);
	}

	public static function fromCounts(int $nodeId, int $withoutAccess, int $total): self
	{
		$state = match (true)
		{
			$withoutAccess <= 0 => self::STATE_FULL,
			$withoutAccess >= $total => self::STATE_NONE,
			default => self::STATE_PARTIAL,
		};

		return new self($nodeId, $state, max(0, $withoutAccess), $total);
	}

	public function isFull(): bool
	{
		return $this->state === self::STATE_FULL;
	}
}
