<?php

namespace Bitrix\StaffTrack\Item;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\StaffTrack\Dictionary\EmployeeOfficeCardStatus;
use Bitrix\StaffTrack\Dictionary\OfficePresence;

final class EmployeeOfficeCard implements Arrayable
{
	public function __construct(
		public EmployeeOfficeCardStatus $status,
		public ?int $officeId = null,
		public ?string $title = null,
		public ?string $snapshotUrl = null,
		public ?string $address = null,
		public ?OfficePresence $presence = null,
	) {}

	public static function available(
		int $officeId,
		string $title,
		?string $snapshotUrl,
		?string $address = null,
		?OfficePresence $presence = null,
	): self
	{
		return new self(
			EmployeeOfficeCardStatus::AVAILABLE,
			$officeId,
			$title,
			$snapshotUrl,
			$address,
			$presence,
		);
	}

	public static function noOffice(): self
	{
		return new self(EmployeeOfficeCardStatus::NO_OFFICE);
	}

	public static function unavailable(): self
	{
		return new self(EmployeeOfficeCardStatus::UNAVAILABLE);
	}

	public function toArray(): array
	{
		return [
			'status' => $this->status->value,
			'officeId' => $this->officeId,
			'title' => $this->title,
			'snapshotUrl' => $this->snapshotUrl,
			'address' => $this->address,
			'presence' => $this->presence?->value,
		];
	}
}
