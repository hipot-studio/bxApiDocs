<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex\Dto;

use Bitrix\Booking\Internals\Validator\Timezone;
use Bitrix\Main\Validation\Rule\NotEmpty;

class CompanyDataDto
{
	public function __construct(
		#[NotEmpty]
		public readonly string $permalink,
		#[Timezone]
		#[NotEmpty]
		public readonly string $timezone,
		#[NotEmpty]
		public readonly string $cabinetLink,
	)
	{
	}
}
