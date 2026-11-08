<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Entity;


use Bitrix\Bizproc\Activity\Dto\NodePorts;
use Bitrix\BizprocDesigner\Internal\Entity\Collection\PortCollection;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\Contract\Arrayable;

final class Block implements EntityInterface, Arrayable
{
	public function __construct(
		public readonly string $id = '',
		public NodeType $type = NodeType::Simple,
		public int $x = 0,
		public int $y = 0,
		public ?int $width = null,
		public ?int $height = null,
		public string $title = '',
		public string $icon = '',
		public PortCollection $ports = new PortCollection(),
		public ActivityData $activityData = new ActivityData(),
		public ?int $colorIndex = null,
		public ?int $contentBlockColor = null,
		public ?FrameData $frameData = null,
	)
	{
	}

	public static function createFromArray(array $data): self
	{
		$portCollection = new PortCollection();

		if (is_array($data['ports']))
		{
			$portCollection->fill($data['ports']);
		}

		$colorIndex = $data['node']['colorIndex'] ?? null;
		$contentBlockColor = $data['node']['contentBlockColor'] ?? null;

		$type = NodeType::tryFrom((string)($data['node']['type'] ?? '')) ?? NodeType::Simple;
		$frameData = $type === NodeType::Frame
			? FrameData::createFromNode((array)($data['node'] ?? []))
			: null
		;

		return new self(
			(string)($data['id'] ?? ''),
			$type,
			(int)($data['position']['x'] ?? 0),
			(int)($data['position']['y'] ?? 0),
			self::readDimension($data['dimensions']['width'] ?? null),
			self::readDimension($data['dimensions']['height'] ?? null),
			(string)($data['node']['title'] ?? ''),
			(string)($data['node']['icon'] ?? ''),
			(new PortCollection())->fill($data['ports']),
			ActivityData::createFromArray((array)($data['activity'] ?? [])),
			is_int($colorIndex) ? $colorIndex : null,
			is_int($contentBlockColor) ? $contentBlockColor : null,
			$frameData,
		);
	}

	private static function readDimension(mixed $value): ?int
	{
		return $value === null ? null : (int)$value;
	}

	public function toArray(): array
	{
		$node = [
			'title' => $this->title,
			'type' => $this->type->value,
			'icon' => $this->icon,
			'colorIndex' => $this->colorIndex,
			'contentBlockColor' => $this->contentBlockColor,
		];

		// A frame block carries the node.frame* section (defaults + overrides) and keeps the top-level
		// block.type = 'frame' the frontend needs for z-order - without it the overlay renders above the group.
		if ($this->type === NodeType::Frame)
		{
			$node += ($this->frameData ?? new FrameData())->toNodeArray();
		}

		return [
			'id' => $this->id,
			'type' => $this->type->value,
			'position' => [
				'x' => $this->x,
				'y' => $this->y,
			],
			'dimensions' => [
				'width' => $this->width,
				'height' => $this->height,
			],
			'node' => $node,
			// Normalize the ports to the canonical flat NodePorts contract (each port carries `type`/`isActive`)
			// - the same shape the modern editor receives on the normal load path (Diagram.get ->
			// TemplateToNodes -> NodePorts) and that its consumers (ports.filter, setUnmountedPorts' forEach)
			// require. Emitting the internal {input,output} grouping here breaks a freshly AI-added node when
			// the pull mounts it. NodePorts::fromArray accepts the legacy {input,output} shape as input.
			'ports' => NodePorts::fromArray($this->ports->toArray())->toArray(),
			'activity' => $this->activityData->toArray(),
		];
	}

	public function getId(): string
	{
		return $this->id;
	}
}
