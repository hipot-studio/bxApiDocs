<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\UserField;

use Bitrix\Main\Type\Date;

class DateField implements UserField
{
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly bool $isEditable,
		public readonly bool $isShowAlways,
		public readonly mixed $value = null,
	)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getValue(): ?Date
	{
		return $this->value;
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
		return $this->value instanceof Date;
	}
}
