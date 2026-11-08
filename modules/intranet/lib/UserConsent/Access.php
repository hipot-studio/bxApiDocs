<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage intranet
 * @copyright 2001-2026 Bitrix
 */

namespace Bitrix\Intranet\UserConsent;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;

/**
 * Right to read the journal of accepted agreements.
 *
 * The journal holds the user, the address and the page of every acceptance, so it belongs to the
 * configuration of the portal and not to the documents it is filtered by: knowing the address of
 * the page is not an admission to it. This is the single source of truth about that right; entry
 * points of other modules add the context right of their own surface on top of it instead of
 * repeating the predicate.
 */
final class Access
{
	public static function canView(): bool
	{
		$currentUser = CurrentUser::get();

		return $currentUser->isAdmin()
			|| (Loader::includeModule('bitrix24') && $currentUser->canDoOperation('bitrix24_config'));
	}
}
