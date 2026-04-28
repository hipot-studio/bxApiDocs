<?php

namespace Bitrix\Crm\Import\Controller\Response;

use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class RequisiteDuplicateControlTarget implements Arrayable, JsonSerializable
{
	public function __construct(
		public int $countryId,
		public string $countryCaption,
		public array $fields = [],
	)
	{
	}

	public function add(string $fieldId, string $fieldCaption): self
	{
		$this->fields[] = [
			'fieldId' => $fieldId,
			'fieldCaption' => $fieldCaption,
		];

		return $this;
	}

	public function toArray(): array
	{
		return [
			'countryId' => $this->countryId,
			'countryCaption' => $this->countryCaption,
			'fields' => $this->fields,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
