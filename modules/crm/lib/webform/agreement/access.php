<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage crm
 * @copyright 2001-2026 Bitrix
 */

namespace Bitrix\Crm\WebForm\Agreement;

use Bitrix\Crm\Service\Container;
use Bitrix\Intranet;
use Bitrix\Main\Loader;

/**
 * Right to open the journal of accepted agreements from the CRM forms surface.
 *
 * The journal belongs to intranet and holds the visitor, the address and the page of every
 * acceptance, so CRM never decides that right on its own: it only adds its own read permission on
 * the forms on top of the answer of intranet. Hiding the entry point is not the protection: the
 * page of intranet denies a direct request by itself, and this class only keeps CRM from
 * offering an entry point that leads to a denial.
 */
final class Access
{
	public static function canViewAcceptedList(): bool
	{
		return Container::getInstance()->getUserPermissions()->webForm()->canRead()
			&& self::isGrantedByIntranet();
	}

	/**
	 * `crm` and `intranet` are released independently, so an intranet that does not know the
	 * capability yet leaves the journal closed instead of failing on a missing class.
	 */
	private static function isGrantedByIntranet(): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			return false;
		}

		if (!method_exists(Intranet\UserConsent\Access::class, 'canView'))
		{
			return false;
		}

		return Intranet\UserConsent\Access::canView();
	}
}
