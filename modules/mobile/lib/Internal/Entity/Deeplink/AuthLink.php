<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Entity\Deeplink;

use Bitrix\Main\Web\Uri;

/**
 * Link of the short link service: it opens the mobile application on the portal page named by the
 * intent code and signs the user in by the one-time hash.
 *
 * The service is given the portal address twice, under two parameter names: `deep_link_value` is
 * read by the service itself, `link` is the name the installed iOS build unwraps the address from.
 */
final class AuthLink
{
	public const DEFAULT_SERVICE_URL = 'https://bitrix24.onelink.me/v3FJ/';

	public const DEFAULT_LINK_PARAM = 'link';

	private const SERVICE_LINK_PARAM = 'deep_link_value';

	private const INTENT_PARTS_DELIMITER = ';';

	/**
	 * @param array{apn?: string, ibi?: string, isi?: string} $appsData application identifiers of the region
	 */
	public function __construct(
		private readonly string $portalUrl,
		private readonly string $intent,
		private readonly string $hash,
		private readonly string $serviceUrl = self::DEFAULT_SERVICE_URL,
		private readonly string $linkParamKey = self::DEFAULT_LINK_PARAM,
		private readonly array $appsData = [],
	)
	{
	}

	public function __toString(): string
	{
		$portalLink = $this->getPortalLink();

		$uri = new Uri($this->serviceUrl);
		$uri->addParams([
			self::SERVICE_LINK_PARAM => $portalLink,
			$this->linkParamKey => $portalLink,
		] + $this->appsData);

		return (string)$uri;
	}

	/**
	 * Portal address the application opens after the sign-in. Both parts of the intent value stay
	 * readable after a single decoding: the delimiter between them is not encoded.
	 */
	private function getPortalLink(): string
	{
		$intentValue = rawurlencode($this->intent) . self::INTENT_PARTS_DELIMITER . rawurlencode($this->hash);

		return $this->portalUrl . '/?intent=' . $intentValue;
	}
}
