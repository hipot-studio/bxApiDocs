<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Catalog;

class Sku
{
	private int $id;
	private string $name;
	private string|null $image = null;
	private float|null $price = null;
	private string|null $currencyId = null;
	private string|null $section = null;

	public function __construct(int $id, string $name)
	{
		$this->id = $id;
		$this->name = $name;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getImage(): string|null
	{
		return $this->image;
	}

	public function setImage(string|null $image): Sku
	{
		$this->image = $image;

		return $this;
	}

	public function getPrice(): float|null
	{
		return $this->price;
	}

	public function setPrice(float|null $price): Sku
	{
		$this->price = $price;

		return $this;
	}

	public function getCurrencyId(): string|null
	{
		return $this->currencyId;
	}

	public function setCurrencyId(string|null $currencyId): Sku
	{
		$this->currencyId = $currencyId;

		return $this;
	}

	public function getSection(): string|null
	{
		return $this->section;
	}

	public function setSection(string|null $section): Sku
	{
		$this->section = $section;

		return $this;
	}
}
