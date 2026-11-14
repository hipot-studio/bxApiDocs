<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Deeplink;

use Bitrix\B24network\Mobile\AppLink;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Mobile\Auth;
use Bitrix\Mobile\Internal\Entity\Deeplink\AuthLink;
use Bitrix\Mobile\Internal\Entity\Deeplink\Payload;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
	 * Announces the hash issued together with a payload to the module the payload belongs to.
	 * Handlers are subscribed by the owner module in its own event migration.
	 */
	private const PAYLOAD_EVENT = 'onDeeplinkPayloadIssued';

	private const LOGGER_ID = 'mobile.deeplink.payload';

	private readonly LoggerInterface $logger;

	public function __construct(?LoggerInterface $logger = null)
	{
		$this->logger = $logger
			?? (new LoggerFactory())->createById(self::LOGGER_ID, isCheckEnabledFromRegistry: false)
			?? new NullLogger()
		;
	}

	/**
	 * Issues a one-time authentication hash for the user and builds the link that signs them in.
	 */
	public function issue(string $intent, ?int $userId = null, ?int $ttl = null, ?Payload $payload = null): AuthLink
	{
		$hash = (string)Auth::getOneTimeAuthHash($userId, $ttl);

		$this->deliverPayload($payload, $userId ?? (int)CurrentUser::get()->getId(), $hash);

		return new AuthLink(
			portalUrl: $this->getPortalUrl(),
			intent: $intent,
			hash: $hash,
			serviceUrl: $this->getServiceUrl(),
			linkParamKey: $this->getLinkParamKey(),
			appsData: $this->getAppsData(),
		);
	}

	/**
	 * The meaning of the payload belongs to its owner: this module only binds it to the hash it has
	 * just issued. The event is addressed to the owner alone, and when nobody is subscribed the
	 * payload is dropped without touching the link.
	 *
	 * The payload only enriches the link, while the link itself is how the whole product signs a person
	 * into the application - so a subscriber that fails costs the payload and not the entry. The kernel
	 * rethrows an exception of a handler and does not catch an error at all, which is what the guard
	 * here stands for.
	 */
	private function deliverPayload(?Payload $payload, int $userId, string $hash): void
	{
		if ($payload === null || $hash === '' || $userId <= 0)
		{
			return;
		}

		try
		{
			(new Event(
				'mobile',
				self::PAYLOAD_EVENT,
				[
					'userId' => $userId,
					'hash' => $hash,
					'data' => $payload->data,
				],
				$payload->module,
			))->send();
		}
		catch (\Throwable $error)
		{
			$this->reportPayloadFailure($payload->module, $userId, $hash, $error);
		}
	}

	/**
	 * The hash signs a person in and the message of an exception may quote it, so it is struck out of
	 * the journal.
	 */
	private function reportPayloadFailure(string $module, int $userId, string $hash, \Throwable $error): void
	{
		$this->logger->error('Deeplink payload handler of {module} failed: {error}', [
			'module' => $module,
			'userId' => $userId,
			'error' => str_replace($hash, '***', $error->getMessage()),
		]);
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
