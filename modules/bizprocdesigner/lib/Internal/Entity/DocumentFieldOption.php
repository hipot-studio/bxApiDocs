<?php

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

class DocumentFieldOption implements Arrayable
{
	public function __construct(
		public readonly string $id,
		public readonly string $name,
	) {}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
		];
	}
}