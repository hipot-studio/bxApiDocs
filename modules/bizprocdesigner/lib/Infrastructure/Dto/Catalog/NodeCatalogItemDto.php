<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Catalog;

use JsonSerializable;

class NodeCatalogItemDto implements JsonSerializable
{
	/**
	 * @param string|null $id
	 * @param string $type
	 * @param string $title
	 * @param string $subtitle
	 * @param string|null $icon
	 * @param string|null $iconPath
	 * @param int|null $colorIndex
	 * @param mixed $properties
	 * @param array $returnProperties
	 * @param array{width: int, height: int, ports: array{input: array, output: array}} $defaultSettings
	 */
	public function __construct(
		public readonly ?string $id,
		public readonly string $type,
		public readonly ?string $presetId,
		public readonly string $title,
		public readonly string $subtitle,
		public readonly ?string $icon,
		public readonly ?string $iconPath,
		public readonly ?int $colorIndex,
		public readonly mixed $properties,
		public readonly array $returnProperties,
		public readonly array $defaultSettings,
	){}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'type' => $this->type,
			'presetId' => $this->presetId,
			'title' => $this->title,
			'subtitle' => $this->subtitle,
			'icon' => $this->icon,
			'iconPath' => $this->iconPath,
			'colorIndex' => $this->colorIndex,
			'properties' => $this->properties,
			'defaultSettings' => $this->defaultSettings,
			'returnProperties' => $this->returnProperties,
		];
	}
}
