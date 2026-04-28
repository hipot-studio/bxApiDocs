<?php

namespace Bitrix\Crm\Import\Dto\Hook\PostSaveHooks;

final class MultipleSaveAddressData
{
	public function __construct(
		private readonly int $addressType,
		private array $addressValues,
	)
	{
	}

	public function getAddressType(): int
	{
		return $this->addressType;
	}

	public function getAddressValues(): array
	{
		return $this->addressValues;
	}

	public function setValue(string $key, mixed $value): self
	{
		$this->addressValues[$key] = $value;

		return $this;
	}
}
