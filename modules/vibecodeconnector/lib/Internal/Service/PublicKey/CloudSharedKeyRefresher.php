<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\PublicKey;

use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\CloudEndpointProvider;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;

final class CloudSharedKeyRefresher
{
	private const CLOUD_ISS = 'vibecode';

	public function __construct(
		private readonly CloudEndpointProvider $cloudEndpointProvider = new CloudEndpointProvider(),
		private readonly CloudKeySourceSettings $sourceSettings = new CloudKeySourceSettings(),
		private readonly PublicKeyFetcherFactory $fetcherFactory = new PublicKeyFetcherFactory(),
		private readonly CloudSharedKeyStore $keyStore = new CloudSharedKeyStore(),
	) {
	}

	public function refresh(): void
	{
		$pem = $this->fetcherFactory
			->make($this->sourceSettings->getSource())
			->fetch(new EndpointResolver($this->cloudEndpointProvider->getCloudUrl()), self::CLOUD_ISS);

		$this->keyStore->set($pem);
	}
}
