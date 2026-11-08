<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Field\Requirement;

final readonly class LegacyRequirementRule
{
	/**
	 * @param list<LegacyRequirementRange> $ranges
	 */
	private function __construct(
		private bool $all,
		private array $ranges,
	)
	{
	}

	public static function none(): self
	{
		return new self(false, []);
	}

	public static function all(): self
	{
		return new self(true, []);
	}

	/**
	 * @param non-empty-list<LegacyRequirementRange> $ranges
	 */
	public static function ranges(array $ranges): self
	{
		return new self(false, array_values($ranges));
	}

	public function isNone(): bool
	{
		return !$this->all && $this->ranges === [];
	}

	public function isAll(): bool
	{
		return $this->all;
	}

	/**
	 * @return list<LegacyRequirementRange>
	 */
	public function getRanges(): array
	{
		return $this->ranges;
	}
}
