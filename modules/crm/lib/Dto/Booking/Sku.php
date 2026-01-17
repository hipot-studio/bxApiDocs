<?php

declare(strict_types=1);

namespace Bitrix\Crm\Dto\Booking;

use Bitrix\Main\Type\Contract\Arrayable;

class Sku implements Arrayable
{
	public function __construct(
		public readonly int $id,
		public readonly string $name,
	)
	{
	}

	public static function mapFromArray(array $params): self
	{
		return new self(
			id: $params['id'],
			name: $params['name'],
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
		];
	}
}
