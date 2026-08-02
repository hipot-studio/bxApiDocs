<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo;

use Bitrix\Main\Web\Uri;

class BoostBuyLink
{
	private const CODE = 'product';
	private const MONTHLY_CODE = 'PACKAGE_DISK_SESSION_P1_Q10';
	private const YEARLY_CODE = 'PACKAGE_DISK_SESSION_P12_Q10';
	private const URL = '/settings/order/make.php';

	public static function monthly(): Uri
	{
		return (new Uri(self::URL))
			->addParams([self::CODE => self::MONTHLY_CODE])
		;
	}

	public static function yearly(): Uri
	{
		return (new Uri(self::URL))
			->addParams([self::CODE => self::YEARLY_CODE])
		;
	}
}