<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Icon;

use Bitrix\Main\ArgumentException;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\BaseEndpointProvider;

/**
 * Single source of truth for icon-origin checks: an icon URL is only allowed when its
 * origin (scheme + host + port) matches the Vibecode endpoint bound to the catalog item.
 */
final class IconOriginPolicy
{
	public function __construct(
		private readonly BaseEndpointProvider $baseEndpointProvider = new BaseEndpointProvider(),
		private readonly PairingRepository $pairingRepository = new PairingRepository(),
	) {
	}

	public function assertAllowed(string $iconUrl, ?string $pairingIss): void
	{
		$allowedEndpoint = $this->resolveAllowedEndpoint($pairingIss);
		if ($this->origin($iconUrl) !== $this->origin($allowedEndpoint))
		{
			throw new ArgumentException('Icon URL origin is not allowed', 'iconUrl');
		}
	}

	private function resolveAllowedEndpoint(?string $pairingIss): string
	{
		if ($pairingIss === null || $pairingIss === '')
		{
			return $this->baseEndpointProvider->getBaseUrl();
		}

		$pairing = $this->pairingRepository->findByIss($pairingIss);
		if ($pairing === null || $pairing->endpointUrl === '')
		{
			return $this->baseEndpointProvider->getBaseUrl();
		}

		return $pairing->endpointUrl;
	}

	private function origin(string $url): string
	{
		$parts = parse_url($url);
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		$host = strtolower((string)($parts['host'] ?? ''));
		$port = $parts['port'] ?? $this->defaultPort($scheme);

		return $scheme . '://' . $host . ':' . $port;
	}

	private function defaultPort(string $scheme): int
	{
		return $scheme === 'https' ? 443 : 80;
	}
}
