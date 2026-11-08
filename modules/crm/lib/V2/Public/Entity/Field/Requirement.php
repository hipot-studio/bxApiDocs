<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Field;

use InvalidArgumentException;

final readonly class Requirement
{
	/**
	 * @param list<string> $requiredStageIds
	 */
	private function __construct(
		private string $fieldId,
		private ?int $categoryId,
		private RequirementMode $mode,
		private array $requiredStageIds,
		private bool $globallyRequired,
	)
	{
	}

	/**
	 * Creates a requirement without an additional rule.
	 */
	public static function none(string $fieldId, ?int $categoryId, bool $globallyRequired): self
	{
		return new self($fieldId, $categoryId, RequirementMode::None, [], $globallyRequired);
	}

	/**
	 * Creates a requirement that applies to every stage.
	 */
	public static function all(string $fieldId, ?int $categoryId, bool $globallyRequired): self
	{
		return new self($fieldId, $categoryId, RequirementMode::All, [], $globallyRequired);
	}

	/**
	 * Creates a requirement that applies to selected stages.
	 *
	 * @param list<string>|array<int, string> $requiredStageIds
	 * @throws InvalidArgumentException If stage identifiers are empty, duplicated, or not strings.
	 */
	public static function stages(
		string $fieldId,
		?int $categoryId,
		array $requiredStageIds,
		bool $globallyRequired,
	): self
	{
		self::validateRequiredStageIds($requiredStageIds);

		return new self(
			$fieldId,
			$categoryId,
			RequirementMode::Stages,
			array_values($requiredStageIds),
			$globallyRequired,
		);
	}

	/**
	 * Returns the external field identifier.
	 */
	public function getFieldId(): string
	{
		return $this->fieldId;
	}

	/**
	 * Returns the category identifier or null for entity types without categories.
	 */
	public function getCategoryId(): ?int
	{
		return $this->categoryId;
	}

	/**
	 * Returns the additional requirement mode.
	 */
	public function getMode(): RequirementMode
	{
		return $this->mode;
	}

	/**
	 * Returns stage identifiers in their canonical order.
	 *
	 * @return list<string>
	 */
	public function getRequiredStageIds(): array
	{
		return $this->requiredStageIds;
	}

	/**
	 * Returns whether the field definition marks the field as required.
	 */
	public function isGloballyRequired(): bool
	{
		return $this->globallyRequired;
	}

	private static function validateRequiredStageIds(array $requiredStageIds): void
	{
		if ($requiredStageIds === [])
		{
			throw new InvalidArgumentException('Required stage identifiers must not be empty.');
		}

		$uniqueStageIds = [];
		foreach ($requiredStageIds as $stageId)
		{
			if (!is_string($stageId) || $stageId === '')
			{
				throw new InvalidArgumentException('Every required stage identifier must be a non-empty string.');
			}

			if (in_array($stageId, $uniqueStageIds, true))
			{
				throw new InvalidArgumentException('Required stage identifiers must be unique.');
			}

			$uniqueStageIds[] = $stageId;
		}
	}
}
