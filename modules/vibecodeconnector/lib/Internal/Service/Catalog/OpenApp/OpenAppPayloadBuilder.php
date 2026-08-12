<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp;

use Bitrix\Main\Security\Random;
use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\OpenApp\OpenAppPayload;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Exception\OpenAppException;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\MainPortalFieldsProvider;
use Bitrix\Vibecodeconnector\Internal\Integration\Socialservices\NetworkService;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\BaseEndpointProvider;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;

final class OpenAppPayloadBuilder
{
	public function __construct(
		private readonly BaseEndpointProvider $baseEndpointProvider = new BaseEndpointProvider(),
		private readonly PairingRepository $pairingRepository = new PairingRepository(),
		private readonly MainPortalFieldsProvider $portalFieldsProvider = new MainPortalFieldsProvider(),
		private readonly NetworkService $networkService = new NetworkService(),
	) {
	}

	public function build(CatalogItem $item, int $userId): OpenAppPayload
	{
		$externalId = trim((string)$item->getExternalId());
		if ($externalId === '')
		{
			throw new OpenAppException(
				'Catalog item external id is missing',
				OpenAppException::CODE_EXTERNAL_ID_MISSING,
			);
		}

		$endpointUrl = $this->resolveEndpointUrl($item);
		if ($endpointUrl === '')
		{
			throw new OpenAppException(
				'Open app endpoint is unavailable',
				OpenAppException::CODE_ENDPOINT_UNAVAILABLE,
			);
		}

		$fields = [
			'BX_TYPE' => $this->portalFieldsProvider->getPortalType(),
			'BX_LICENCE' => $this->portalFieldsProvider->getLicenseCode(),
			'BX_REGION' => $this->portalFieldsProvider->getLicenseRegion(),
			'SERVER_NAME' => $this->portalFieldsProvider->getServerName(),
			'BITRIX_USER_ID' => (string)$userId,
		];

		if ($this->networkService->isCloudPortal())
		{
			$networkUserId = $this->networkService->getUserNetworkId($userId);
			if ($networkUserId !== null)
			{
				$fields['BX_NETWORK_USER_ID'] = $networkUserId;
			}
		}

		$fields += [
			'EXTERNAL_ID' => $externalId,
			'TS' => (string)time(),
			'NONCE' => Random::getString(32, true),
		];
		$fields['BX_HASH'] = $this->portalFieldsProvider->sign($fields);

		return new OpenAppPayload(
			(new EndpointResolver($endpointUrl))->microservice() . '/open-app/',
			$fields,
		);
	}

	private function resolveEndpointUrl(CatalogItem $item): string
	{
		$pairingIss = trim((string)$item->getPairingIss());
		if ($pairingIss !== '')
		{
			$pairing = $this->pairingRepository->findByIss($pairingIss);
			if ($pairing !== null && trim($pairing->endpointUrl) !== '')
			{
				return trim($pairing->endpointUrl);
			}
		}

		return trim($this->baseEndpointProvider->getBaseUrl());
	}
}
