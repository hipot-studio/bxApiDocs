<?php

namespace Bitrix\StaffTrack\Item;

use Bitrix\Main\Type\Contract\Arrayable;

class OfficeAddress implements Arrayable
{
	public function __construct(
		public string $value,
		public float $latitude,
		public float $longitude,
	) {}

	public function toArray(): array
	{
		return [
			'value' => $this->value,
			'latitude' => $this->latitude,
			'longitude' => $this->longitude,
		];
	}
}
