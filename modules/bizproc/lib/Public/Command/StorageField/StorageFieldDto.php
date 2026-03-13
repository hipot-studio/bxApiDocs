<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\StorageField;

class StorageFieldDto
{
	public function __construct(
		public readonly ?int $id = null,

		public readonly ?string $code = null,

		public readonly ?int $storageId = null,

		public readonly ?string $type = null,

		public readonly string $multiple = 'N',

		public readonly string $mandatory = 'N',

		public readonly ?string $name = null,

		public readonly int $sort = 500,

		public readonly string $description = '',

		public readonly bool $format = false,
	) {
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'code' => $this->code,
			'storageId' => $this->storageId,
			'type' => $this->type,
			'multiple' => $this->multiple,
			'mandatory' => $this->mandatory,
			'name' => $this->name,
			'sort' => $this->sort,
			'description' => $this->description,
		];
	}
}
