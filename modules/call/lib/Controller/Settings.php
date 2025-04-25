<?php

namespace Bitrix\Call\Controller;

use Bitrix\Call\Error;
use Bitrix\Main\Engine\Controller;
use Bitrix\Call\Settings as CallSettings;


class Settings extends Controller
{
	/**
	 * Generates a secret key for call JWT
	 *
	 * @restMethod call.Settings.registerKey
	 */
	public function registerKeyAction(): void
	{
		$result = CallSettings::registerPortalKey();

		if (!$result)
		{
			$this->addError(new Error("Failed register portal key", "failed_register_portal_key"));
		}
	}
}
