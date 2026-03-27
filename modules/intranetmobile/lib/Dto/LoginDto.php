<?php

namespace Bitrix\IntranetMobile\Dto;

use Bitrix\Mobile\Dto\Dto;

class LoginDto extends Dto
{
	public function __construct(
		public readonly int $id,
		public readonly int $loginDate,
		public readonly ?string $deviceType,
		public readonly ?string $devicePlatform,
		public readonly ?string $browser,
		public readonly ?string $address,
		public readonly ?string $ip,

	)
	{
		parent::__construct();
	}
}