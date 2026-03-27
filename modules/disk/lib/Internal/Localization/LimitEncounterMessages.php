<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Localization;

use Bitrix\Main\Localization\Loc;
use InvalidArgumentException;

final class LimitEncounterMessages
{
	public static function adminPortalNotificationTitle($languageId = null): string
	{
		return (string)Loc::getMessage('DISK_LIMIT_ENCOUNTER_ADMIN_PORTAL_NOTIFICATION_TITLE', null, $languageId);
	}

	/**
	 * @param int $thresholdValue
	 * @param int $thresholdIndex
	 * @param bool $isCloud
	 * @param $languageId
	 * @return string
	 */
	public static function adminPortalNotificationDocumentEditSessionThresholdReached(
		int $thresholdValue,
		int $thresholdIndex,
		bool $isCloud,
		$languageId = null,
	): string
	{
		$code = 'DISK_LIMIT_ENCOUNTER_ADMIN_PORTAL_NOTIFICATION_DOCUMENT_EDIT_SESSION_THRESHOLD_'
			. $thresholdIndex
			. '_REACHED_'
			. ($isCloud ? 'CLOUD' : 'ON_PREMISE');

		$message = (string)Loc::getMessage(
			$code,
			[
				'#LIMIT_REACHED_COUNT#' => $thresholdValue,
				'[buymonthlink]' => '[url=/settings/order/make.php?product=PACKAGE_DISK_SESSION_P1_Q10&st[tool]=docs&st[category]=infohelper&st[event]=open_link&st[type]=docs_boost_month]',
				'[/buymonthlink]' => '[/url]',
				'[buyyearlink]' => '[url=/settings/order/make.php?product=PACKAGE_DISK_SESSION_P12_Q10&st[tool]=docs&st[category]=infohelper&st[event]=open_link&st[type]=docs_boost_year]',
				'[/buyyearlink]' => '[/url]',
			],
			$languageId,
		);

		if ($message === '')
		{
			throw new InvalidArgumentException(sprintf('Message with code "%s" not found for language "%s".', $code, $languageId ?? 'default'));
		}

		return $message;
	}
}