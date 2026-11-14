<?php

declare(strict_types=1);

namespace Bitrix\StaffTrackMobile\Internal\Integration\Timeman;

use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Public\Provider\CheckInSettingsProvider;
use Bitrix\Timeman\V2\Public\Event\OnFullReportSavedEvent;
use Bitrix\Timeman\V2\Public\Provider\FullReportProvider;

/**
 * Readiness of the full report of timeman for sending. The version of timeman is told by the names before its new
 * API is called: an older timeman is asked through the facade of stafftrack, as before.
 */
class FullReportReadiness
{
	/**
	 * Tells whether timeman announces the written reports and answers their readiness without building them.
	 *
	 * @return bool
	 */
	public function isNewTimeman(): bool
	{
		return
			Loader::includeModule('timeman')
			&& class_exists(OnFullReportSavedEvent::class)
			&& method_exists(FullReportProvider::class, 'isReportReadyForSubmit')
		;
	}

	/**
	 * Tells whether the full report of the user is ready for sending: the report day has come or a draft is kept.
	 * Accurate in the session of the user, as the readiness of timeman is; on an older timeman the facade of
	 * stafftrack answers for the user of the session.
	 *
	 * @param int $userId
	 * @return bool
	 */
	public function isReportReadyForSubmit(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		if ($this->isNewTimeman())
		{
			return (new FullReportProvider())->isReportReadyForSubmit($userId);
		}

		return $this->getSettingsProvider()->hasReportReadyForSubmit();
	}

	protected function getSettingsProvider(): CheckInSettingsProvider
	{
		return new CheckInSettingsProvider();
	}
}
