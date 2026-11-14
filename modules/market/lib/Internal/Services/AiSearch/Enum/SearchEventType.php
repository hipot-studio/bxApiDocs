<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Services\AiSearch\Enum;

enum SearchEventType: string
{
	case SearchResultClicked = 'search_result_clicked';
	case AppDetailOpened = 'app_detail_opened';
	case AppInstallStarted = 'app_install_started';
	case AppInstallCompleted = 'app_install_completed';

	public function isClient(): bool
	{
		return $this !== self::AppInstallCompleted;
	}
}
