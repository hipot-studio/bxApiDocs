<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Summary;

/**
 * One section of a summary: its state, its payload and whether the payload is the whole of it.
 *
 * The named constructors are the only way to build a section, so a state that cannot be explained
 * does not exist: a payload belongs to {@see self::filled()} alone, and being truncated is derived
 * from having a reason rather than being a flag of its own.
 *
 * @internal
 */
final class SummarySection
{
	private function __construct(
		public readonly SectionState $state,
		public readonly ?array $data,
		public readonly ?TruncationReason $truncationReason,
	)
	{
	}

	public static function filled(array $data, ?TruncationReason $truncationReason = null): self
	{
		return new self(SectionState::Filled, $data, $truncationReason);
	}

	public static function empty(): self
	{
		return new self(SectionState::Empty, null, null);
	}

	public static function restricted(): self
	{
		return new self(SectionState::Restricted, null, null);
	}

	public function isTruncated(): bool
	{
		return $this->truncationReason !== null;
	}

	public function toArray(): array
	{
		return [
			'state' => $this->state->value,
			'truncated' => $this->isTruncated(),
			'truncationReason' => $this->truncationReason?->value,
			'data' => $this->data,
		];
	}
}
