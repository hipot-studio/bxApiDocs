<?php

declare(strict_types=1);

namespace Bitrix\Booking\Entity\Sku;

use Bitrix\Booking\Entity\EntityInterface;

class Sku implements EntityInterface
{
	public const PERMISSION_READ = 'read';

	private int|null $id = null;
	private string|null $name = null;
	private float|null $price = null;
	private string|null $currencyId = null;
	private array $permissions = [];

	public function getId(): int|null
	{
		return $this->id;
	}

	public function setId(int|null $id): static
	{
		$this->id = $id;
		return $this;
	}

	public function getName(): string|null
	{
		return $this->name;
	}

	public function setName(string|null $name): static
	{
		$this->name = $name;

		return $this;
	}

	public function getPrice(): float|null
	{
		return $this->price;
	}

	public function setPrice(float|null $price): static
	{
		$this->price = $price;

		return $this;
	}

	public function getCurrencyId(): string|null
	{
		return $this->currencyId;
	}

	public function setCurrencyId(string|null $currencyId): static
	{
		$this->currencyId = $currencyId;

		return $this;
	}

	public function getPermissions(): array
	{
		return $this->permissions;
	}

	public function setPermissions(array $permissions): static
	{
		$this->permissions = $permissions;

		return $this;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'price' => $this->price,
			'currencyId' => $this->currencyId,
			'permissions' => $this->permissions,
		];
	}

	public static function mapFromArray(array $props): static
	{
		return (new static())
			->setId($props['id'] ?? null)
			->setName($props['name'] ?? null)
			->setPrice($props['price'] ?? null)
			->setCurrencyId($props['currencyId'] ?? null)
			->setPermissions($props['permissions'] ?? [])
		;
	}
}
