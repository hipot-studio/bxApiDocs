<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\UserField;

class SelectField implements UserField
{
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly bool $isEditable,
		public readonly bool $isShowAlways,
		/** @var $items array<string, string> */
		public readonly array $items,
		public readonly ?string $value = null,
	)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getValue(): ?string
	{
		return $this->isValid() ? $this->value : null;
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
		return is_string($this->value) && array_key_exists($this->value, $this->items);
	}
}
