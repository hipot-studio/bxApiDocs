<?php

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

class BlockType implements Arrayable
{
	public function __construct(
		public readonly string $type,
		public readonly string $description,
	) {}

	public function toArray(): array
	{
		return [
			'type' => $this->type,
			'description' => $this->description,
		];
	}
}