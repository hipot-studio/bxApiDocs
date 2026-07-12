<?php

declare(strict_types=1);

namespace Bitrix\Im\Integration\Network;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Loader;
use Bitrix\Main\Service\MicroService\Client;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Psr\Log\LoggerInterface;

/**
 * Keeps portal_id for guest-invite short links and syncs it with network.
 */
final class GuestNetworkPortalRegistry
{
	private const STORAGE_KEY_PORTAL_ID = 'im.guest_network_portal_id';

	private const SYNC_PATH = '/gi/sync/';
	private const SYNC_TTL = 3600;

	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly PersistentStorageInterface $storage,
	)
	{
		$this->logger = (new LoggerFactory())->createById('im.guestNetworkPortalRegistry');
	}

	public function getPortalId(): ?string
	{
		$storedId = $this->storage->get(self::STORAGE_KEY_PORTAL_ID);
		if (is_string($storedId) && $storedId !== '')
		{
			return $storedId;
		}

		return $this->syncWithNetwork();
	}

	private function syncWithNetwork(): ?string
	{
		if (!Loader::includeModule('socialservices'))
		{
			return null;
		}

		$basePayload = [
			'ROOT_URL' => Client::getServerName(),
			'REGION' => Application::getInstance()->getLicense()->getRegion(),
			'BX_TYPE' => Client::getPortalType(),
			'BX_LICENCE' => Client::getLicenseCode(),
		];
		$requestBody = $basePayload;
		$requestBody['BX_HASH'] = Client::signRequest($basePayload);

		$http = new HttpClient(['socketTimeout' => 5, 'streamTimeout' => 5]);

		$response = $http->post(\CSocServBitrix24Net::NETWORK_URL . self::SYNC_PATH, $requestBody);
		if ($response === false)
		{
			$this->logger->error(
				'network_portal_register_sync: HTTP request failed',
				['httpError' => $http->getError()],
			);

			return null;
		}

		$status = (int)$http->getStatus();
		if ($status < 200 || $status >= 300)
		{
			$this->logger->error(
				'network_portal_register_sync: unexpected HTTP status',
				[
					'status' => $status,
					'response' => mb_substr((string)$response, 0, 100),
				],
			);

			return null;
		}

		try
		{
			$data = Json::decode((string)$response);
		}
		catch (ArgumentException)
		{
			$this->logger->error(
				'network_portal_register_sync: response is not valid JSON',
				[
					'status' => $status,
					'response' => mb_substr((string)$response, 0, 100),
				],
			);

			return null;
		}

		$portalId = $data['portalId'] ?? null;
		if (!is_string($portalId) || $portalId === '')
		{
			$this->logger->error(
				'network_portal_register_sync: portalId missing or invalid',
				[
					'error' => isset($data['error'])
						? $data['error']
						: mb_substr((string)$response, 0, 100)
					,
				],
			);

			return null;
		}

		$this->storage->set(self::STORAGE_KEY_PORTAL_ID, $portalId, self::SYNC_TTL);

		return $portalId;
	}
}
