<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Registration;

use Bitrix\Vibecodeconnector\Internal\Entity\Pairing\Pairing;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Auth\CloudSharedVerifier;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Deactivation\PairingCatalogDeactivator;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointUrlGuard;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\PublicKeySource;

final class RegistrationService
{
	public function __construct(
		private readonly PairingRepository $repository = new PairingRepository(),
		private readonly PairingSettings $settings = new PairingSettings(),
		private readonly CloudSharedVerifier $cloudSharedVerifier = new CloudSharedVerifier(),
		private readonly PairingCatalogDeactivator $catalogDeactivator = new PairingCatalogDeactivator(),
		private readonly EndpointUrlGuard $urlGuard = new EndpointUrlGuard(),
	) {
	}

	public function register(string $endpointUrl): Pairing
	{
		$url = $endpointUrl;
		$this->urlGuard->assertValid($url);

		$response = (new RegistrationClient(new EndpointResolver($url)))->register();

		$now = time();
		$pairing = new Pairing(
			iss: $response->iss,
			publicKey: $response->publicKey,
			portalId: $response->portalId,
			endpointUrl: $url,
			fetchedAt: $now,
			expiresAt: $this->settings->computeExpiresAt($now, $response->ttlSeconds),
			publicKeyTtl: $response->ttlSeconds,
			keySource: PublicKeySource::default(),
		);
		$this->repository->upsert($pairing);

		return $pairing;
	}

	public function unregister(string $iss): void
	{
		$pairing = $this->repository->findByIss($iss);
		if ($pairing === null)
		{
			$this->catalogDeactivator->deactivateByIss($iss);
			$this->repository->deleteByIss($iss);

			return;
		}

		(new RegistrationClient(new EndpointResolver($pairing->endpointUrl)))->unregister($iss);
		$this->catalogDeactivator->deactivateByIss($iss);
		$this->repository->deleteByIss($iss);
	}

	public function isRegistered(): bool
	{
		return $this->repository->hasAny();
	}

	public function isCloudSharedConfigured(): bool
	{
		return $this->cloudSharedVerifier->isConfigured();
	}

	public function listPairings(): array
	{
		return $this->repository->listAll();
	}
}
