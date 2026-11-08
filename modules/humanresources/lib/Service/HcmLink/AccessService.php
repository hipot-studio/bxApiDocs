<?php

namespace Bitrix\HumanResources\Service\HcmLink;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main;
use Bitrix\Sign;

class AccessService
{
	public function __construct() {}

	public function canRead(): bool
	{
		$user = CurrentUser::get();
		if (!$user->getId())
		{
			return false;
		}

		if (Main\Loader::includeModule('sign'))
		{
			$accessController = (new Sign\Access\AccessController($user->getId()));

			return $accessController->check(Sign\Access\ActionDictionary::ACTION_B2E_DOCUMENT_ADD);
		}

		return $user->isAdmin();
	}

	/**
	 * Managing exclusions is a named level of its own, not an alias of canRead(): today it answers the
	 * same, which is what the feature requires, but the two stop being indistinguishable in code, and
	 * tightening the exclusions later becomes a change of one method instead of a search for callers.
	 */
	public function canManageExclusions(): bool
	{
		return $this->canRead();
	}

	/**
	 * Disconnecting an integration is irreversible, so it is limited to portal administrators.
	 * canRead() must not stand in for this check: it asks whether the user may create a B2E document,
	 * a right the default "Employee" role gives to every employee of the portal.
	 */
	public function canDisconnect(): bool
	{
		$user = CurrentUser::get();
		if (!$user->getId())
		{
			return false;
		}

		return $user->isAdmin();
	}
}