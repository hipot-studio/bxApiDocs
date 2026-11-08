<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Deeplink;

use Bitrix\B24network\Mobile\AppLink;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Mobile\Auth;
use Bitrix\Mobile\Internal\Entity\Deeplink\AuthLink;

/**
 * Assembles the authentication link of the mobile application: the portal address, the intent code
 * and the region application identifiers are known here, the short link service address is chosen
 * by {@see ServiceUrl}.
 */
final class AuthLinkService
{
	private const RU_APP_REGIONS = ['ru', 'kz', 'by'];

	private const DEFAULT_REGION = 'en';

	/**
	 * Issues a one-time authentication hash for the user and builds the link that signs them in.
	 */
	public function issue(string $intent, ?int $userId = null, ?int $ttl = null): AuthLink
	{
		return new AuthLink(
			portalUrl: $this->getPortalUrl(),
			intent: $intent,
			hash: (string)Auth::getOneTimeAuthHash($userId, $ttl),
			serviceUrl: $this->getServiceUrl(),
			linkParamKey: $this->getLinkParamKey(),
			appsData: $this->getAppsData(),
		);
	}

	private function getPortalUrl(): string
	{
		$context = Context::getCurrent();
		$host = defined('BX24_HOST_NAME') ? BX24_HOST_NAME : $context->getServer()->getHttpHost();
		$scheme = $context->getRequest()->isHttps() ? 'https' : 'http';

		return $scheme . '://' . preg_replace("/:(443|80)$/", "", $host);
	}

	/**
	 * @return array{apn: string, ibi: string, isi: string} application identifiers of the region
	 */
	private function getAppsData(): array
	{
		$region = Application::getInstance()->getLicense()->getRegion() ?? self::DEFAULT_REGION;
		$ruAppEnabled = Option::get('mobile', 'ru_app_enable', 'N') === 'Y';

		if (!in_array($region, self::RU_APP_REGIONS, true) || !$ruAppEnabled)
		{
			return [
				'apn' => 'com.bitrix24.android',
				'ibi' => 'com.bitrixsoft.cpmobile',
				'isi' => '561683423',
			];
		}

		return [
			'apn' => 'ru.bitrix.bitrix24',
			'ibi' => 'ru.bitrix.bitrix24',
			'isi' => '6670570479',
		];
	}

	private function getServiceUrl(): string
	{
		return ServiceUrl::resolve(
			configured: (string)Option::get('mobile', 'deeplink_service_url', ''),
			network: $this->getNetworkServiceUrl(),
			default: AuthLink::DEFAULT_SERVICE_URL,
		);
	}

	/**
	 * The network module builds application links of its own and owns the service the portal
	 * belongs to. Empty when the module is not installed.
	 */
	private function getNetworkServiceUrl(): string
	{
		return Loader::includeModule('b24network') ? AppLink::SERVICE_URL : '';
	}

	private function getLinkParamKey(): string
	{
		return (string)Option::get('mobile', 'deeplink_link_param_key', AuthLink::DEFAULT_LINK_PARAM);
	}
}
