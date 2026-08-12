<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\PublicKey;

use Bitrix\Vibecodeconnector\Internal\Entity\Pairing\Pairing;
use Bitrix\Vibecodeconnector\Internal\Exception\PublicKeyFetchFailedException;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;
use Bitrix\Vibecodeconnector\Internal\Service\Registration\PairingSettings;

final class PairingKeyRefresher
{
	public function __construct(
		private readonly PairingRepository $repository = new PairingRepository(),
		private readonly PublicKeyFetcherFactory $fetcherFactory = new PublicKeyFetcherFactory(),
		private readonly PairingSettings $settings = new PairingSettings(),
	) {
	}

	public function refresh(string $iss): Pairing
	{
		$existing = $this->repository->findByIss($iss);
		if ($existing === null)
		{
			throw new PublicKeyFetchFailedException(
				"No pairing for iss='{$iss}' — refresh requires existing registration",
				'PAIRING_NOT_FOUND',
			);
		}

		$publicKey = $this->fetcherFactory
			->make($existing->keySource)
			->fetch(new EndpointResolver($existing->endpointUrl), $iss);

		$now = time();
		$pairing = new Pairing(
			iss: $existing->iss,
			publicKey: $publicKey,
			portalId: $existing->portalId,
			endpointUrl: $existing->endpointUrl,
			fetchedAt: $now,
			expiresAt: $this->settings->computeExpiresAt($now, $existing->publicKeyTtl),
			publicKeyTtl: $existing->publicKeyTtl,
			keySource: $existing->keySource,
		);
		$this->repository->upsert($pairing);

		return $pairing;
	}
}
