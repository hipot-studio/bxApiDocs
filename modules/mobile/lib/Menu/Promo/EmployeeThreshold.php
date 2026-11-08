<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Promo;

use Bitrix\Main\Config\Option;

/**
 * The mark "the portal has grown past the invite promo": raised once the company reaches COUNT
 * active employees and never lowered by the product again. A momentary employee count cannot stand
 * for it - a company that shrank back to nine employees is still past the stage the promo is for.
 *
 * Two readers share the mark - the floating promo of the menu and the invite aha moment of the
 * section - so the option name, the count behind it and the three operations over it live here
 * alone. The dev page of the section is the only caller of forget().
 */
final class EmployeeThreshold
{
	public const COUNT = 10;
	private const OPTION_CODE = 'my_company_employee_threshold_reached';

	public function isReached(): bool
	{
		return (bool)Option::get('mobile', self::OPTION_CODE, false);
	}

	public function remember(): void
	{
		Option::set('mobile', self::OPTION_CODE, true);
	}

	public function forget(): void
	{
		Option::delete('mobile', ['name' => self::OPTION_CODE]);
	}
}
