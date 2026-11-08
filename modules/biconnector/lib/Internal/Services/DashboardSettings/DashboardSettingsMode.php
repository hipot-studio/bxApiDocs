<?php

namespace Bitrix\BIConnector\Internal\Services\DashboardSettings;

enum DashboardSettingsMode: string
{
	case Full = 'full';
	/** Only the public links setting and read-only base information, for a controller without the settings right. */
	case Reduced = 'reduced';
	case Denied = 'denied';
}
