<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Preview;

use Bitrix\Main\Web\Uri;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\ImGate;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Auth\CloudSharedVerifier;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\CloudEndpointProvider;

final class BlackholeAppLinkDetector
{
	private const CLOUD_ISS = 'vibecode';
	private const MIN_BASE_DOMAIN_LABELS = 2;

	private ?array $serverBaseDomains = null;
	private ?string $portalHost = null;

	public function __construct(
		private readonly CatalogItemRepository $catalogItemRepository = new CatalogItemRepository(),
		private readonly PairingRepository $pairingRepository = new PairingRepository(),
		private readonly CloudEndpointProvider $cloudEndpointProvider = new CloudEndpointProvider(),
		private readonly CloudSharedVerifier $cloudSharedVerifier = new CloudSharedVerifier(),
		private readonly ImGate $imGate = new ImGate(),
	) {}

	public function detect(string $url): ?int
	{
		if (str_contains($url, '\\'))
		{
			return null;
		}

		$uri = new Uri($url);
		$host = rtrim($uri->getHost(), '.');
		if (
			$host === ''
			|| $uri->getUser() !== ''
			|| $uri->getPass() !== ''
			|| !$this->belongsToVibecodeServer($host)
			|| $host === $this->portalHost()
		)
		{
			return null;
		}

		return $this->catalogItemRepository->findIdByViewAddress($host, rtrim($uri->getPath(), '/'));
	}

	private function belongsToVibecodeServer(string $host): bool
	{
		foreach ($this->serverBaseDomains ??= $this->collectServerBaseDomains() as $domain)
		{
			if ($host === $domain || str_ends_with($host, '.' . $domain))
			{
				return true;
			}
		}

		return false;
	}

	private function portalHost(): string
	{
		return $this->portalHost ??= rtrim((new Uri($this->imGate->getPublicDomain()))->getHost(), '.');
	}

	private function collectServerBaseDomains(): array
	{
		$urls = $this->pairingRepository->listEndpointUrls();
		if ($this->cloudSharedVerifier->isApplicable(self::CLOUD_ISS))
		{
			$urls[] = $this->cloudEndpointProvider->getCloudUrl();
		}

		$domains = [];
		foreach ($urls as $url)
		{
			$host = rtrim((new Uri($url))->getHost(), '.');
			if ($host !== '')
			{
				$domains[] = $this->baseDomain($host);
			}
		}

		return array_unique($domains);
	}

	private function baseDomain(string $host): string
	{
		$labels = explode('.', $host);

		return count($labels) > self::MIN_BASE_DOMAIN_LABELS ? implode('.', array_slice($labels, 1)) : $host;
	}
}
