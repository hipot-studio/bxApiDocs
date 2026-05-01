<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Service\Otp;

use Bitrix\Intranet\Internal\Integration\Messageservice\TwoFaNetworkSender;
use Bitrix\Main\Loader;

class SmsSenderConfigurator
{
	public function useNetworkSenderIfCloud(): void
	{
		if (Loader::includeModule('messageservice'))
		{
			TwoFaNetworkSender::useIfCloud();
		}
	}
}
