<?php

namespace Bitrix\StaffTrackMobile\Infrastructure\Controllers\CheckIn;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\StaffTrackMobile\Infrastructure\Controllers\Base;
use Bitrix\StaffTrackMobile\Public\Providers\CheckIn\LocationProvider;

class Location extends Base
{
	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.Location.getList
	 * @return array
	 */
	#[CloseSession]
	public function getListAction(): array
	{
		return (new LocationProvider())->getList();
	}
}
