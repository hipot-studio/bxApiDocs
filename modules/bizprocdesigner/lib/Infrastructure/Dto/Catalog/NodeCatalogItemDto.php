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
	 * @param int|null $contentBlockColor
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
		public readonly ?int $contentBlockColor,
		public readonly mixed $properties,
		public readonly array $returnProperties,
		public readonly array $defaultSettings,
		public readonly bool $hasAuxPorts,
		/** Whether the node is served by the unified settings panel; absent reads as `false`. */
		public readonly bool $servedByUnifiedPanel = false,
		/**
		 * Whether the node offers the relations block. The verdict is two-valued on purpose: the resolver
		 * answers "no relations" for a node whose declaration says nothing about them, so the client is
		 * never handed an undecided state to interpret on its own.
		 */
		public readonly bool $relationsAvailable = false,
		public readonly ?array $contentBlock = null,
		public readonly ?array $contentBlockProducer = null,
		public readonly ?array $contentBlockConsumer = null,
	){}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'type' => $this->type,
			'servedByUnifiedPanel' => $this->servedByUnifiedPanel,
			'presetId' => $this->presetId,
			'title' => $this->title,
			'subtitle' => $this->subtitle,
			'icon' => $this->icon,
			'iconPath' => $this->iconPath,
			'colorIndex' => $this->colorIndex,
			'contentBlockColor' => $this->contentBlockColor,
			'properties' => $this->properties,
			'defaultSettings' => $this->defaultSettings,
			'returnProperties' => $this->returnProperties,
			'hasAuxPorts' => $this->hasAuxPorts,
			'relationsAvailable' => $this->relationsAvailable,
			'contentBlock' => $this->contentBlock,
			'contentBlockProducer' => $this->contentBlockProducer,
			'contentBlockConsumer' => $this->contentBlockConsumer,
		];
	}
}
