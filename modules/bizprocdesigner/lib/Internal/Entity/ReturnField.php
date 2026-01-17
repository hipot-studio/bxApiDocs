<?php

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

class ReturnField implements Arrayable
{
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly string $type,
	) {}

	public function toArray(): array
	{
		return [
			'name' => $this->name,
			'description' => $this->description,
			'type' => $this->type,
		];
	}
}