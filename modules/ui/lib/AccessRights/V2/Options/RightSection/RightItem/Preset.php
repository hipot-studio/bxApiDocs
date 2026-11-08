<?php

namespace Bitrix\UI\AccessRights\V2\Options\RightSection\RightItem;

use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

class Preset implements JsonSerializable, Arrayable
{
	protected ?string $icon = null;
	protected ?string $description = null;
	protected bool $showGroupHeadItems = false;
	protected array $variableIds = [];

	public function __construct(
		protected string $id,
		protected string $title,
	)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function setId(string $id): static
	{
		$this->id = $id;

		return $this;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function setTitle(string $title): static
	{
		$this->title = $title;

		return $this;
	}

	public function getIcon(): ?string
	{
		return $this->icon;
	}

	public function setIcon(?string $icon): static
	{
		$this->icon = $icon;

		return $this;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	public function setDescription(?string $description): static
	{
		$this->description = $description;

		return $this;
	}

	public function shouldShowGroupHeadItems(): bool
	{
		return $this->showGroupHeadItems;
	}

	public function setShowGroupHeadItems(bool $showGroupHeadItems): static
	{
		$this->showGroupHeadItems = $showGroupHeadItems;

		return $this;
	}

	public function getVariableIds(): array
	{
		return $this->variableIds;
	}

	public function setVariableIds(array $variableIds): static
	{
		$this->variableIds = array_values(array_map(static fn ($id): string => (string)$id, $variableIds));

		return $this;
	}

	public function addVariableId(string $variableId): static
	{
		$this->variableIds[] = $variableId;

		return $this;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->getId(),
			'title' => $this->getTitle(),
			'icon' => $this->getIcon(),
			'description' => $this->getDescription(),
			'showGroupHeadItems' => $this->shouldShowGroupHeadItems(),
			'variableIds' => $this->getVariableIds(),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	public static function tryFromArray(array $data): ?self
	{
		if (!isset($data['id']))
		{
			return null;
		}

		if (!isset($data['title']) || !is_string($data['title']))
		{
			return null;
		}

		$preset = new self(
			(string)$data['id'],
			$data['title'],
		);

		if (isset($data['icon']))
		{
			$preset->setIcon((string)$data['icon']);
		}

		if (isset($data['description']))
		{
			$preset->setDescription((string)$data['description']);
		}

		if (isset($data['showGroupHeadItems']))
		{
			$preset->setShowGroupHeadItems((bool)$data['showGroupHeadItems']);
		}

		if (isset($data['variableIds']) && is_array($data['variableIds']))
		{
			$preset->setVariableIds($data['variableIds']);
		}

		return $preset;
	}
}
