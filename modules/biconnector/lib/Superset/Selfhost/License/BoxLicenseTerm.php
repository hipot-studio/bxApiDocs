<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Selfhost\License;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

/**
 * Term of the boxed portal license, as the instance is told it.
 *
 * Not a field of the license of the extension: this term belongs to the license of the product and is read from
 * the licensing layer of the portal, not from an option this feature writes.
 */
final class BoxLicenseTerm
{
	/**
	 * The licensing layer keeps a date without a time of day, and the license works through the whole of that day.
	 * The instance compares the term with its own clock, so it is handed the end of the day: the beginning would
	 * close access a day earlier than the portal does.
	 */
	private const LAST_SECOND_OF_DAY = 86399;

	/**
	 * The boxed product closes access fifteen days after the paid term ends, and the renewal at a discount is sold
	 * within those days. Blocking earlier than the product itself would take the reports away from a portal that
	 * still works.
	 */
	private const GRACE_DAYS = 15;

	private const GRACE_DAYS_OPTION = 'selfhost_box_license_grace_days';

	/**
	 * Null when there is nothing to hand over: a license that is not time bound has no term, and an instance
	 * without a stored term is not blocked by one.
	 */
	public static function getExpiryTimestamp(): ?int
	{
		$license = Application::getInstance()->getLicense();
		if (!$license->isTimeBound())
		{
			return null;
		}

		$expireDate = $license->getExpireDate();
		if ($expireDate === null)
		{
			return null;
		}

		return $expireDate->getTimestamp() + self::LAST_SECOND_OF_DAY + self::getGraceDays() * 86400;
	}

	/**
	 * The length of the grace is a rule of the product, so the constant is the answer; the option is for a stand
	 * and for support, where the term has to be moved without touching the license.
	 */
	private static function getGraceDays(): int
	{
		$configured = (int)Option::get('biconnector', self::GRACE_DAYS_OPTION, (string)self::GRACE_DAYS);

		return max(0, $configured);
	}
}
