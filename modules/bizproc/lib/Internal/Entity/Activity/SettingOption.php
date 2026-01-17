<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity;

use Bitrix\Main\Type\Contract\Arrayable;

class SettingOption implements Arrayable
{
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $description = '',
	) {}

	public function toArray(): array
	{
		$array = [
			'id' => $this->id,
			'name' => $this->name,
		];

		if ($this->description)
		{
			$array['description'] = $this->description;
		}

		return $array;
	}
}