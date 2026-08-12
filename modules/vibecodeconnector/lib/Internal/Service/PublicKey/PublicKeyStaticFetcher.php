<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\PublicKey;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Vibecodeconnector\Internal\Exception\PublicKeyFetchFailedException;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;

final class PublicKeyStaticFetcher implements PublicKeyFetcher
{
	private const PEM_HEAD_MARKER = '-----BEGIN PUBLIC KEY-----';
	private const PEM_TAIL_MARKER = '-----END PUBLIC KEY-----';
	private const MAX_BYTES = 16 * 1024;

	public function __construct(
		private readonly HttpClient $httpClient = new HttpClient([
			'streamTimeout' => 10,
			'socketTimeout' => 10,
			'redirect' => false,
		]),
	) {
	}

	public function fetch(EndpointResolver $endpoints, string $iss): string
	{
		$url = $endpoints->publicKey();

		$body = $this->httpClient->get($url);
		$status = $this->httpClient->getStatus();

		if ($body === false || $status !== 200)
		{
			throw new PublicKeyFetchFailedException(
				'Public key fetch failed: HTTP ' . (int)$status,
				'UPSTREAM_UNAVAILABLE',
			);
		}

		if (strlen($body) > self::MAX_BYTES)
		{
			throw new PublicKeyFetchFailedException(
				'Public key response is too large',
				'INVALID_RESPONSE',
			);
		}

		$pem = trim($body);
		if ($pem === '' || !str_contains($pem, self::PEM_HEAD_MARKER) || !str_contains($pem, self::PEM_TAIL_MARKER))
		{
			throw new PublicKeyFetchFailedException(
				'Public key response is not a PEM-encoded public key',
				'INVALID_RESPONSE',
			);
		}

		return $pem;
	}
}
