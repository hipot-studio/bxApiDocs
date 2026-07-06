<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Column;

/**
 * Snapshot of all column properties consumed by Renderer.
 * Fields that don't apply to a particular column type stay at their default values.
 */
final class ColumnParams
{
	public function __construct(
		private readonly string $name,
		private readonly bool $notNull = false,
		/** Raw default value without quotes; null if no default. */
		private readonly ?string $defaultValue = null,
		// int family
		private readonly ?int $size = null,
		private readonly bool $unsigned = false,
		private readonly bool $autoincrement = false,
		// varchar / char / varbinary
		private readonly ?int $length = null,
		// float family
		private readonly ?int $mantissaLength = null,
		private readonly ?int $exponentLength = null,
		// datetime family
		private readonly bool $currentTimestampAsDefault = false,
		private readonly bool $currentTimestampOnUpdate = false,
	)
	{
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function isNotNull(): bool
	{
		return $this->notNull;
	}

	public function getDefaultValue(): ?string
	{
		return $this->defaultValue;
	}

	public function getSize(): ?int
	{
		return $this->size;
	}

	public function isUnsigned(): bool
	{
		return $this->unsigned;
	}

	public function isAutoincrement(): bool
	{
		return $this->autoincrement;
	}

	public function getLength(): ?int
	{
		return $this->length;
	}

	public function getMantissaLength(): ?int
	{
		return $this->mantissaLength;
	}

	public function getExponentLength(): ?int
	{
		return $this->exponentLength;
	}

	public function isCurrentTimestampAsDefault(): bool
	{
		return $this->currentTimestampAsDefault;
	}

	public function isCurrentTimestampOnUpdate(): bool
	{
		return $this->currentTimestampOnUpdate;
	}
}
