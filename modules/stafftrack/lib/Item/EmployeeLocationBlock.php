<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Item;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\StaffTrack\Dictionary\EmployeeLocationBlockMode;
use Bitrix\StaffTrack\Dictionary\OfficePresence;

final class EmployeeLocationBlock implements Arrayable
{
	public function __construct(
		public EmployeeLocationBlockMode $mode,
		public ?int $officeId,
		public ?string $officeTitle,
		public ?string $address,
		public ?int $time,
		public ?string $dateCreate,
		public ?int $timezoneOffset,
		public ?string $snapshotUrl,
		public bool $isRouteAvailable,
		public string $mapUrl,
		public ?OfficePresence $presence = null,
		public ?string $avatarUrl = null,
	) {}

	public static function route(
		?string $address,
		int $time,
		?string $dateCreate,
		?int $timezoneOffset,
		?string $snapshotUrl,
		string $mapUrl,
		?string $avatarUrl = null,
	): self
	{
		return new self(
			EmployeeLocationBlockMode::ROUTE,
			null,
			null,
			$address,
			$time,
			$dateCreate,
			$timezoneOffset,
			$snapshotUrl,
			true,
			$mapUrl,
			null,
			$avatarUrl,
		);
	}

	public static function office(
		int $officeId,
		?string $officeTitle,
		?string $address,
		?string $snapshotUrl,
		bool $isRouteAvailable,
		string $mapUrl,
		?OfficePresence $presence = null,
	): self
	{
		return new self(
			EmployeeLocationBlockMode::OFFICE,
			$officeId,
			$officeTitle,
			$address,
			null,
			null,
			null,
			$snapshotUrl,
			$isRouteAvailable,
			$mapUrl,
			$presence,
		);
	}

	public function toArray(): array
	{
		return [
			'mode' => $this->mode->value,
			'officeId' => $this->officeId,
			'officeTitle' => $this->officeTitle,
			'address' => $this->address,
			'time' => $this->time,
			'dateCreate' => $this->dateCreate,
			'timezoneOffset' => $this->timezoneOffset,
			'snapshotUrl' => $this->snapshotUrl,
			'isRouteAvailable' => $this->isRouteAvailable,
			'mapUrl' => $this->mapUrl,
			'presence' => $this->presence?->value,
			'avatarUrl' => $this->avatarUrl,
		];
	}
}
