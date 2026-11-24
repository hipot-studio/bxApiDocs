<?php

namespace Bitrix\Im\V2\Controller;

use Bitrix\Im\V2\Application\ClientConfig;
use Bitrix\Im\V2\Application\ClientDeviceType;
use Bitrix\Im\V2\Rest\RestError;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Web\Json;

class Application extends BaseController
{
	/**
	 * The round trip through JSON turns the objects of the config into plain arrays for the REST output.
	 *
	 * @restMethod im.v2.Application.config
	 *
	 * @param string $deviceType the client mode of the config: "browser" (default) or "desktop";
	 * any other value gives the INVALID_DEVICE_TYPE error. The desktop signs of the request are not read.
	 */
	public function configAction(?\CRestServer $restServer = null, string $deviceType = 'browser'): ?array
	{
		$clientDeviceType = ClientDeviceType::tryFrom($deviceType);
		if ($clientDeviceType === null)
		{
			$this->addError(new RestError(RestError::INVALID_DEVICE_TYPE));

			return null;
		}

		$withActiveCalls = self::isActiveCallsAllowed(
			$restServer?->getAuthScope(),
			$restServer?->getAuthType(),
			$this->getScope(),
		);

		return Json::decode(Json::encode(new ClientConfig(
			$clientDeviceType->createContext(),
			withActiveCalls: $withActiveCalls,
		)));
	}

	/**
	 * The active calls carry the call tokens: over REST they need the "call" scope of the auth,
	 * the ajax session of the web page is entitled to them as is.
	 * Only the standard auth types are trusted: other providers (e.g. signed proxies) may grant
	 * the whole scope envelope to batch commands, so their "call" scope does not prove the right.
	 */
	private static function isActiveCallsAllowed(
		?array $restAuthScopes,
		?string $restAuthType,
		string $controllerScope,
	): bool
	{
		if ($restAuthScopes !== null)
		{
			$trustedAuthTypes = [
				\Bitrix\Rest\OAuth\Auth::AUTH_TYPE,
				\Bitrix\Rest\APAuth\Auth::AUTH_TYPE,
				\Bitrix\Rest\SessionAuth\Auth::AUTH_TYPE,
			];

			return
				in_array($restAuthType, $trustedAuthTypes, true)
				&& in_array('call', $restAuthScopes, true)
			;
		}

		return $controllerScope === Controller::SCOPE_AJAX;
	}
}
