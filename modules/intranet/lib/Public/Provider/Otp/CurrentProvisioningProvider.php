<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Provider\Otp;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Access\Otp\UserPermission;
use Bitrix\Intranet\Internal\Integration\Security\CurrentOtpReader;

class CurrentProvisioningProvider
{
	public function __construct(private ?CurrentOtpReader $reader = null)
	{
	}

	public function get(User $user): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();
		if (!$user->isCurrent() || !(new UserPermission($user))->canEdit())
		{
			return $result->addError(new \Bitrix\Main\Error('Access denied.', 'ACCESS_DENIED'));
		}

		try
		{
			$uri = ($this->reader ??= new CurrentOtpReader())->getProvisioningUri($user);
		}
		catch (\Throwable)
		{
			return $result->addError(new \Bitrix\Main\Error('Current OTP is unavailable.', 'OTP_UNAVAILABLE'));
		}

		return $result->setData(['provisioningUri' => $uri]);
	}

	public function canShow(User $user): bool
	{
		if (!$user->isCurrent() || !(new UserPermission($user))->canEdit())
		{
			return false;
		}

		return ($this->reader ??= new CurrentOtpReader())->isAvailable($user);
	}
}
