<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Cleanup;

/**
 * Outcome of a single cleanup pass. The completed step alone cannot tell a step that still has rows
 * to delete from a sequence with no steps left: both of them report "no step completed yet".
 */
final class CleanupProgress
{
	private function __construct(
		public readonly bool $isFinished,
		public readonly ?string $completedStep,
	){}

	public static function finished(): self
	{
		return new self(true, null);
	}

	public static function inProgress(?string $completedStep): self
	{
		return new self(false, $completedStep);
	}
}
