<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Service\Office;

final class OfficeAddress
{
	public function __construct(public readonly ?string $address)
	{
	}
}
