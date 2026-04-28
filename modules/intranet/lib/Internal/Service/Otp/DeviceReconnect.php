<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

class DeviceReconnect
{
	public function isDeviceLost(): bool
	{
		return \CUserOptions::GetOption('intranet', 'push_otp_device_lost', null) === 'Y';
	}

	public function shouldShowReconnect(): bool
	{
		return $this->isDeviceLost();
	}

	public function markLost(): void
	{
		\CUserOptions::SetOption('intranet', 'push_otp_device_lost', 'Y');
	}

	public function clearLost(int $userId): void
	{
		\CUserOptions::DeleteOption('intranet', 'push_otp_device_lost', false, $userId);
	}
}
