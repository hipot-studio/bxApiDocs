<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1\Response\CalendarData;

use Bitrix\Booking\Entity\Sku\Sku;
use Bitrix\Main\Type\Contract\Arrayable;

class ServiceDto implements Arrayable
{
	public function __construct(
		public readonly int $id,
		public readonly string|null $name,
		public readonly array $permissions,
	)
	{
	}

	public static function fromEntity(Sku $sku): self
	{
		return new self(
			id: $sku->getId(),
			name: $sku->getName(),
			permissions: $sku->getPermissions(),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'permissions' => $this->permissions,
		];
	}
}
