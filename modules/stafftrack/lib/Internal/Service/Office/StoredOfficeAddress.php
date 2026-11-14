<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\StaffTrack\Internal\Model\Office;

final class StoredOfficeAddress
{
	public function __construct(
		public readonly ?string $address,
		public readonly float $latitude,
		public readonly float $longitude,
	)
	{
	}

	public static function fromOffice(Office $office): self
	{
		return new self(
			$office->getAddress(),
			(float)$office->getLatitude(),
			(float)$office->getLongitude(),
		);
	}
}
