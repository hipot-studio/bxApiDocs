<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Balance;

use Bitrix\Main\Service\MicroService\Client;
use Bitrix\Vibecodeconnector\Internal\Integration;

class AdminRecipientProvider
{
	public function getAdminUserIds(): array
	{
		if (Client::getPortalType() === Client::TYPE_BITRIX24)
		{
			return (new Integration\Bitrix24\PortalAdminUsers())->getAdminIds();
		}

		return (new Integration\Intranet\PortalAdminUsers())->getAdminIds();
	}
}
