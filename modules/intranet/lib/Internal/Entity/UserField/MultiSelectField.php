<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\UserField;
class MultiSelectField implements UserField
{
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly bool $isEditable,
		public readonly bool $isShowAlways,
		/** @var $items array<string, string> */
		public readonly array $items,
		public readonly array $value = [],
	)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getValue(): array
	{
		return $this->isValid() ? $this->value : [];
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function isEditable(): bool
	{
		return $this->isEditable;
	}

	public function isShowAlways(): bool
	{
		return $this->isShowAlways;
	}

	public function isValid(): bool
	{
		return is_array($this->value) && !empty($this->value);
	}
}
