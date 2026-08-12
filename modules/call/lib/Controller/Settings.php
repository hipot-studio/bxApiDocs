<?php

namespace Bitrix\Call\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Call\Error;
use Bitrix\Call\JwtCall;
use Bitrix\Call\Signaling;

/**
 * @internal
 */
class Settings extends Controller
{
	/**
	 * Generates a secret key for call JWT
	 * @restMethod call.Settings.registerKey
	 */
	public function registerKeyAction(): void
	{
		if (!$this->getCurrentUser()?->isAdmin())
		{
			$this->addError(new Error('access_denied', 'Only portal administrator can register the call key'));
			return;
		}

		$result = JwtCall::registerPortal();
		if ($result->isSuccess())
		{
			Signaling::sendClearCallTokens();
		}
		else
		{
			$this->addError($result->getError());
		}
	}
}
