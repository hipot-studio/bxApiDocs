<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

use Bitrix\Main\Type\Contract\Arrayable;

final class NodeSettings implements Arrayable, \JsonSerializable
{
	public function __construct(
		public readonly ?int $width = null,
		public readonly ?int $height = null,
		public readonly ?NodePorts $ports = null,
	)
	{}

	public static function fromArray(array $array): self
	{
		return new self(
			width: $array['width'] ?? null,
			height: $array['height'] ?? null,
			ports: is_array($array['ports'] ?? null) ? NodePorts::fromArray($array['ports']) : null,
		);
	}

	public function toArray(): array
	{
		return [
			'width' => $this->width,
			'height' => $this->height,
			'ports' => $this->ports?->toArray(),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
