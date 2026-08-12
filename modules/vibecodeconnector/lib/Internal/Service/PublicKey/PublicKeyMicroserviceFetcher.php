<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\PublicKey;

use Bitrix\Main\Service\MicroService\BaseSender;
use Bitrix\Vibecodeconnector\Internal\Exception\PublicKeyFetchFailedException;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;

final class PublicKeyMicroserviceFetcher extends BaseSender implements PublicKeyFetcher
{
	private const ACTION = 'getPublicKey';
	private const PEM_HEAD_MARKER = '-----BEGIN PUBLIC KEY-----';
	private const PEM_TAIL_MARKER = '-----END PUBLIC KEY-----';
	private const MAX_BYTES = 16 * 1024;

	private ?EndpointResolver $endpoints = null;

	public function fetch(EndpointResolver $endpoints, string $iss): string
	{
		if ($iss === '')
		{
			throw new PublicKeyFetchFailedException('iss is required', 'ISS_REQUIRED');
		}

		$this->endpoints = $endpoints;

		$result = $this->performRequest(self::ACTION, ['iss' => $iss]);
		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$first = $errors[0] ?? null;

			throw new PublicKeyFetchFailedException(
				'Public key fetch failed: ' . implode('; ', $result->getErrorMessages()),
				$first?->getCode() !== null ? (string)$first->getCode() : null,
			);
		}

		$data = $result->getData();
		$publicKey = trim((string)($data['public_key'] ?? ''));

		if ($publicKey === '')
		{
			throw new PublicKeyFetchFailedException(
				'Public key response missing public_key',
				'INVALID_RESPONSE',
			);
		}

		if (strlen($publicKey) > self::MAX_BYTES)
		{
			throw new PublicKeyFetchFailedException(
				'Public key response is too large',
				'INVALID_RESPONSE',
			);
		}

		if (!str_contains($publicKey, self::PEM_HEAD_MARKER) || !str_contains($publicKey, self::PEM_TAIL_MARKER))
		{
			throw new PublicKeyFetchFailedException(
				'Public key response is not a PEM-encoded public key',
				'INVALID_RESPONSE',
			);
		}

		return $publicKey;
	}

	protected function getServiceUrl(): string
	{
		return $this->endpoints?->microservice() ?? '';
	}
}
