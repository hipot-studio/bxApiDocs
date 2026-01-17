<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Entity;


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
		public int $width = 0,
		public int $height = 0,
		public string $title = '',
		public string $icon = '',
		public PortCollection $ports = new PortCollection(),
		public ActivityData $activityData = new ActivityData(),
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

		return new Block(
			(string)($data['id'] ?? ''),
			NodeType::tryFrom((string)($data['node']['type'] ?? '')) ?? NodeType::Simple,
			(int)($data['position']['x'] ?? 0),
			(int)($data['position']['y'] ?? 0),
			(int)($data['dimensions']['width'] ?? 0),
			(int)($data['dimensions']['height'] ?? 0),
			(string)($data['node']['title'] ?? ''),
			(string)($data['node']['icon'] ?? ''),
			(new PortCollection())->fill($data['ports']),
			ActivityData::createFromArray((array)($data['activity'] ?? [])),
		);
	}

	public function toArray(): array
	{
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
			'node' => [
				'title' => $this->title,
				'type' => $this->type->value,
				'icon' => $this->icon,
			],
			'ports' => $this->ports->toArray(),
			'activity' => $this->activityData->toArray(),
		];
	}

	public function getId(): string
	{
		return $this->id;
	}
}
