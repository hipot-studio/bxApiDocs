<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Entity;

use Bitrix\Intranet\Internal\Enum\Otp\UserOtpStatus;
use Bitrix\Main\Type\DateTime;

class UserOtpStatusInfo
{
	public function __construct(
		public readonly UserOtpStatus $status,
		public readonly ?DateTime $graceDate = null,
	) {}
}
