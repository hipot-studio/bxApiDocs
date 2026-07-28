<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\MessageEditor\Channel;

final readonly class From implements \JsonSerializable
{
	public function __construct(
		private string $id,
		private string $name,
		private ?string $description = null,
		private bool $isDefault = false,
		private bool $isAvailable = true,
		private ?string $type = null,
	)
	{
	}

	public static function fromArray(array $data): ?self
	{
		$id = $data['id'] ?? null;
		$name = $data['name'] ?? null;

		if (is_numeric($id))
		{
			$id = (string)$id;
		}

		if (!is_string($id) || $id === '' || !is_string($name))
		{
			return null;
		}

		$description = isset($data['description']) && is_string($data['description']) ? $data['description'] : null;
		$isDefault = isset($data['isDefault']) && is_bool($data['isDefault']) ? $data['isDefault'] : false;
		$isAvailable = isset($data['isAvailable']) && is_bool($data['isAvailable']) ? $data['isAvailable'] : true;
		$type = isset($data['type']) && is_string($data['type']) ? $data['type'] : null;

		return new self(
			$id,
			$name,
			$description,
			$isDefault,
			$isAvailable,
			$type,
		);
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
