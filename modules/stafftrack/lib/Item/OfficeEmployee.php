<?php

namespace Bitrix\StaffTrack\Item;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\StaffTrack\Dictionary\OfficePresence;

class OfficeEmployee implements Arrayable
{
	public function __construct(
		public int $id,
		public string $name,
		public ?string $avatar,
		public ?string $workPosition,
		public OfficePresence $presence,
	) {}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'avatar' => $this->avatar,
			'workPosition' => $this->workPosition,
			'presence' => $this->presence->value,
		];
	}
}
