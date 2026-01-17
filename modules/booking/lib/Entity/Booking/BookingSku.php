<?php

declare(strict_types=1);

namespace Bitrix\Booking\Entity\Booking;

use Bitrix\Booking\Entity\Sku\Sku;

class BookingSku extends Sku
{
	private int|null $productRowId = null;

	public function getProductRowId(): int|null
	{
		return $this->productRowId;
	}

	public function setProductRowId(int|null $productRowId): self
	{
		$this->productRowId = $productRowId;

		return $this;
	}

	public function toArray(): array
	{
		return [
			...parent::toArray(),
			'productRowId' => $this->productRowId,
		];
	}

	public static function mapFromArray(array $props): static
	{
		return parent::mapFromArray($props)
			->setProductRowId($props['productRowId'] ?? null)
		;
	}
}
