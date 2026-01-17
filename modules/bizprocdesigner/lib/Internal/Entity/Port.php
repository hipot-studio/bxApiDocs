<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Entity;


use Bitrix\BizprocDesigner\Internal\Enum\PortDirection;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\Contract\Arrayable;

final class Port implements EntityInterface, Arrayable
{
	public function __construct(
		public readonly string $id = '',
		public PortDirection $direction = PortDirection::Output,
		public int $position = 0,
	) {}

	public static function createFromArray(array $data, PortDirection $direction = PortDirection::Output): self
	{
		return new Port(
			(string)($data['id'] ?? ''),
			$direction,
			(int)($data['position'] ?? ''),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'position' => $this->position,
		];
	}

	public function getId(): string
	{
		return $this->id;
	}
}
