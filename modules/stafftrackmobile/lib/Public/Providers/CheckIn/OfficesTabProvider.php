<?php

namespace Bitrix\StaffTrackMobile\Public\Providers\CheckIn;

use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Mobile\Context;
use Bitrix\StaffTrackMobile\Internal\CheckInTab;

/**
 * Tells whether the current person has the offices tab among the tabs of the check-in. The menu of the app
 * builds the tab, and a link to an office asks the same rule to choose between the tab and the web card.
 */
class OfficesTabProvider
{
	public function isAvailable(): bool
	{
		try
		{
			if (!Loader::includeModule('mobile'))
			{
				return false;
			}

			$tab = new CheckInTab();
			$tab->setContext(new Context());

			return $tab->hasOfficesTab();
		}
		catch (LoaderException)
		{
			return false;
		}
	}
}
