<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Model\ResourceLinkedEntityData;

use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Integration\Catalog\ExternalData\PermissionDto;
use Bitrix\Booking\Internals\Integration\Catalog\Sku;

class CatalogSkuData implements ResourceLinkedEntityDataInterface
{
	public function __construct(
		public readonly int $id,
		public readonly string|null $name = null,
		public readonly PermissionDto $permissions,
	)
	{
	}

	public static function fromSku(Sku $sku): self
	{
		return new self(
			id: $sku->getId(),
			name: $sku->getName(),
			permissions: new PermissionDto(true),
		);
	}

	public static function buildEmpty(int $id): self
	{
		return new self(
			id: $id,
			name: null,
			permissions: new PermissionDto(),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'permissions' => $this->permissions->toArray(),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	public static function mapFromArray(array $props): static
	{
		throw new Exception('method mapFromArray() does not exist');
	}
}
