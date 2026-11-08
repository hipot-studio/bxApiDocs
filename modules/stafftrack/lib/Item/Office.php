<?php

namespace Bitrix\StaffTrack\Item;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\StaffTrack\Item\Collection\OfficeEmployeeCollection;
use Bitrix\StaffTrack\Item\Collection\OfficePhotoCollection;

class Office implements Arrayable
{
	public function __construct(
		public int $id,
		public string $title,
		public OfficeAddress $address,
		public int $geoZoneRadius,
		public OfficePhotoCollection $photos,
		public int $assignedCount,
		public bool $isEmployeeListAvailable,
		public OfficeEmployeeCollection $employees,
		public bool $canEdit,
		public ?OfficeWorkingHours $workingHours = null,
	) {}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'address' => $this->address->toArray(),
			'geoZoneRadius' => $this->geoZoneRadius,
			'workingHours' => $this->workingHours?->toArray(),
			'photos' => $this->photos->toArray(),
			'assignedCount' => $this->assignedCount,
			'isEmployeeListAvailable' => $this->isEmployeeListAvailable,
			'employees' => $this->employees->toArray(),
			'canEdit' => $this->canEdit,
		];
	}
}
