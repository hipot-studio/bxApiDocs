<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Service\Office;

interface OfficeAddressGeocoder
{
	public function shortAddress(float $latitude, float $longitude): ?string;
}
