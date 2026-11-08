<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Deeplink;

/**
 * Address of the short link service the authentication link is built on. The portal option wins
 * because it is set deliberately; without it the address of the network module is preferred over
 * the built-in one, so a portal follows the service the network itself uses.
 */
final class ServiceUrl
{
	/**
	 * @param string $configured value of the portal option, empty when the option is not set
	 * @param string $network address of the network module, empty when the module is absent
	 * @param string $default built-in address of the service
	 */
	public static function resolve(string $configured, string $network, string $default): string
	{
		foreach ([$configured, $network, $default] as $address)
		{
			$address = trim($address);

			if ($address !== '')
			{
				return $address;
			}
		}

		return '';
	}
}
