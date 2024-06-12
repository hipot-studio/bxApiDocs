<?php

namespace Bitrix\Crm\Security\Role\Manage\DTO;

class EntityFieldDTO
{
	public function __construct(
		private string $fieldName,
		/** @var array<string, string> */
		private array $values
	)
	{
	}

	public function fieldName(): string
	{
		return $this->fieldName;
	}

	public function values(): array
	{
		return $this->values;
	}
}