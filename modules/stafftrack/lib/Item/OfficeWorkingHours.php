<?php

namespace Bitrix\StaffTrack\Item;

use Bitrix\Main\Type\Contract\Arrayable;

class OfficeWorkingHours implements Arrayable
{
	public function __construct(
		public string $from,
		public string $to,
	) {}

	public function toArray(): array
	{
		return [
			'from' => $this->from,
			'to' => $this->to,
		];
	}
}
