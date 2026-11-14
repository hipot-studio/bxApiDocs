<?php

namespace Bitrix\StaffTrack\Item;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\StaffTrack\Dictionary\OfficePresence;

final class EmployeeSearchMatch implements Arrayable
{
	public function __construct(
		public int $id,
		public string $name,
		public ?string $avatar,
		public ?string $workPosition,
		public ?OfficePresence $presence,
		public ?int $officeId,
		public ?string $officeTitle,
	) {}

	public function toArray(): array
	{
		return [
			'employee' => [
				'id' => $this->id,
				'name' => $this->name,
				'avatar' => $this->avatar,
				'workPosition' => $this->workPosition,
				'presence' => $this->presence?->value,
			],
			'office' => $this->officeId === null
				? null
				: [
					'id' => $this->officeId,
					'title' => (string)$this->officeTitle,
				],
		];
	}
}
