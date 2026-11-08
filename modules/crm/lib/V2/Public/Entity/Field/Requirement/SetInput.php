<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Field\Requirement;

final readonly class SetInput
{
	private function __construct(
		private bool $hasRequiredStageIds,
		private ?array $requiredStageIds,
	)
	{
	}

	/**
	 * Creates input without an explicitly supplied stage selection.
	 */
	public static function withoutRequiredStageIds(): self
	{
		return new self(false, null);
	}

	/**
	 * Creates input with an explicitly supplied stage selection.
	 *
	 * @param array<array-key, mixed>|null $ids
	 */
	public static function withRequiredStageIds(?array $ids): self
	{
		return new self(true, $ids);
	}

	/**
	 * Returns whether the stage selection was explicitly supplied.
	 */
	public function hasRequiredStageIds(): bool
	{
		return $this->hasRequiredStageIds;
	}

	/**
	 * Returns the supplied stage selection or null.
	 *
	 * @return array<array-key, mixed>|null
	 */
	public function getRequiredStageIds(): ?array
	{
		return $this->requiredStageIds;
	}

	/**
	 * Returns whether an explicit empty stage selection requests clearing the requirement.
	 */
	public function isClearRequested(): bool
	{
		return $this->hasRequiredStageIds && $this->requiredStageIds === [];
	}
}
