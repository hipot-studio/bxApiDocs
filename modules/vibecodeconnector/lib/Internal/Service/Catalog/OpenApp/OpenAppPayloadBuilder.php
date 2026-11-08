<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp;

use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\OpenApp\OpenAppPayload;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Exception\OpenAppException;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\MainPortalFieldsProvider;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\PortalUserProvider;
use Bitrix\Vibecodeconnector\Internal\Integration\Socialservices\NetworkService;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Transition\TransitionFieldsBuilder;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Transition\TransitionSurface;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\BaseEndpointProvider;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;

final class OpenAppPayloadBuilder
{
	private readonly TransitionFieldsBuilder $fieldsBuilder;

	public function __construct(
		private readonly BaseEndpointProvider $baseEndpointProvider = new BaseEndpointProvider(),
		private readonly PairingRepository $pairingRepository = new PairingRepository(),
		MainPortalFieldsProvider $portalFieldsProvider = new MainPortalFieldsProvider(),
		NetworkService $networkService = new NetworkService(),
		PortalUserProvider $portalUserProvider = new PortalUserProvider(),
	) {
		$this->fieldsBuilder = new TransitionFieldsBuilder(
			$portalFieldsProvider,
			$networkService,
			$portalUserProvider,
		);
	}

	public function build(CatalogItem $item, int $userId, array $embeddingFields = []): OpenAppPayload
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

		$fields = $this->fieldsBuilder->build(
			TransitionSurface::App,
			$userId,
			['EXTERNAL_ID' => $externalId] + $embeddingFields,
		);

		return new OpenAppPayload(
			(new EndpointResolver($endpointUrl))->microservice() . '/open-app/',
			$fields,
			trim((string)$item->getTitle()),
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
