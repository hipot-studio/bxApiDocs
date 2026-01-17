<?php

namespace Bitrix\Rest\V3\Dto;

use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Contract\Arrayable;

class DtoFieldRelation implements Arrayable
{
	public function __construct(
		public readonly string $thisField,
		public readonly string $refField,
		public readonly ?array $sort = null,
		public readonly bool $multiple = false,
	) {
	}

	public static function fromArray(array $data): self
	{
		if (empty($data['thisField']) || empty($data['refField']))
		{
			throw new SystemException('thisField и refField обязательны для DtoFieldRelation');
		}

		return new self(
			thisField: $data['thisField'],
			refField: $data['refField'],
			sort: $data['sort'] ?? null,
			multiple: $data['multiple'] ?? false,
		);
	}

	public function toArray(): array
	{
		return [
			'thisField' => $this->thisField,
			'refField' => $this->refField,
			'sort' => $this->sort,
			'multiple' => $this->multiple,
		];
	}
}
