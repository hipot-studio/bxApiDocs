<?php

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

class DocumentField implements Arrayable
{
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly bool $editable = false,
		public readonly ?DocumentFieldOptionCollection $options = null,
	) {}

	public function toArray(): array
	{
		$serialized = [
			'id' => $this->id,
			'name' => $this->name,
			'editable' => $this->editable,
		];

		if ($this->options)
		{
			$serialized['options'] = $this->options->toArray();
		}

		return $serialized;
	}
}