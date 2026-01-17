<?php

namespace Bitrix\Bizproc\Internal\Entity\StorageField;

use Bitrix\Bizproc\Internal\Entity\EntityInterface;

class StorageField implements EntityInterface
{
	private ?int $id = null;
	private ?int $storageId = null;
	private ?string $code = null;
	private ?int $sort = null;
	private ?string $name = null;
	private ?string $description = null;
	private ?string $type = null;
	private ?bool $multiple = null;
	private ?bool $mandatory = null;
	private ?array $settings = null;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function isNew(): bool
	{
		return (int)$this->id === 0;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getStorageId(): ?int
	{
		return $this->storageId;
	}

	public function setStorageId(?int $storageId): self
	{
		$this->storageId = $storageId;

		return $this;
	}

	public function getCode(): ?string
	{
		return $this->code;
	}

	public function setCode(?string $code): self
	{
		$this->code = $code;

		return $this;
	}

	public function getSort(): ?int
	{
		return $this->sort;
	}

	public function setSort(?int $sort): self
	{
		$this->sort = $sort;

		return $this;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function setName(?string $name): self
	{
		$this->name = $name;

		return $this;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	public function setDescription(?string $description): self
	{
		$this->description = $description;

		return $this;
	}

	public function getType(): ?string
	{
		return $this->type;
	}

	public function setType(?string $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function getMultiple(): ?bool
	{
		return $this->multiple;
	}

	public function setMultiple(?bool $multiple): self
	{
		$this->multiple = $multiple;

		return $this;
	}

	public function getMandatory(): ?bool
	{
		return $this->mandatory;
	}

	public function setMandatory(?bool $mandatory): self
	{
		$this->mandatory = $mandatory;

		return $this;
	}

	public function getSettings(): ?array
	{
		return $this->settings;
	}

	public function setSettings(?array $settings): self
	{
		$this->settings = $settings;

		return $this;
	}

	public static function mapFromArray(array $props): static
	{
		$result = new self();

		if (isset($props['id']))
		{
			$result->setId((int)$props['id']);
		}

		if (isset($props['storageId']))
		{
			$result->setStorageId((int)$props['storageId']);
		}

		if (isset($props['code']))
		{
			$result->setCode((string)$props['code']);
		}

		if (isset($props['sort']))
		{
			$result->setSort((int)$props['sort']);
		}

		if (isset($props['name']))
		{
			$result->setName((string)$props['name']);
		}

		if (isset($props['description']))
		{
			$result->setDescription((string)$props['description']);
		}

		if (isset($props['type']))
		{
			$result->setType((string)$props['type']);
		}

		if (isset($props['multiple']))
		{
			$result->setMultiple($props['multiple'] === 'Y');
		}

		if (isset($props['mandatory']))
		{
			$result->setMandatory($props['mandatory'] === 'Y');
		}

		if (isset($props['settings']))
		{
			$result->setSettings((array)$props['settings']);
		}

		return $result;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'storageId' => $this->storageId,
			'code' => $this->code,
			'sort' => $this->sort,
			'name' => $this->name,
			'description' => $this->description,
			'type' => $this->type,
			'multiple' => $this->multiple,
			'mandatory' => $this->mandatory,
			'settings' => $this->settings,
		];
	}

	public function toProperty(): array
	{
		return [
			'Id' => $this->id,
			'StorageId' => $this->storageId,
			'FieldName' => $this->code,
			'Sort' => $this->sort,
			'Name' => $this->name,
			'Description' => $this->description,
			'Type' => $this->type,
			'Multiple' => $this->multiple,
			'Required' => $this->mandatory,
			'Options' => $this->settings,
		];
	}
}
