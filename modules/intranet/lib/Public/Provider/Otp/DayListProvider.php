<?php

namespace Bitrix\Intranet\Public\Provider\Otp;

use Bitrix\Intranet\Internal\Enum\Otp\PromoteMode;
use Bitrix\Intranet\Internal\Service\Otp\MobilePush;
use Bitrix\Main\Localization\Loc;

class DayListProvider
{
	public function getList(): array
	{
		$mobilePush = MobilePush::createByDefault();

		if ($mobilePush->getPromoteMode() !== PromoteMode::High)
		{
			$days[] = Loc::getMessage("INTRANET_DAY_LIST_PROVIDER_NO_DAYS");
		}

		for ($i=1; $i<=10; $i++)
		{
			$days[$i] = FormatDate("ddiff", time()-60*60*24*$i);
		}

		return $days;
	}
}
