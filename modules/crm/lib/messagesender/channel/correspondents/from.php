<?php

namespace Bitrix\Crm\MessageSender\Channel\Correspondents;

final class From implements \JsonSerializable
{
	private string $id;
	private string $name;
	private ?string $description;
	private bool $isDefault;
	private bool $isAvailable;
	private ?string $type;

	public function __construct(
		string $id,
		string $name,
		?string $description = null,
		bool $isDefault = false,
		bool $isAvailable = true,
		?string $type = null,
	)
	{
		$this->id = $id;
		$this->name = $name;
		$this->description = $description;
		$this->isDefault = $isDefault;
		$this->isAvailable = $isAvailable;
		$this->type = $type;
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	public function isDefault(): bool
	{
		return $this->isDefault;
	}

	public function isAvailable(): bool
	{
		return $this->isAvailable;
	}

	public function getType(): ?string
	{
		return $this->type;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'description' => $this->description,
			'isDefault' => $this->isDefault,
			'isAvailable' => $this->isAvailable,
			'type' => $this->type,
		];
	}
}
