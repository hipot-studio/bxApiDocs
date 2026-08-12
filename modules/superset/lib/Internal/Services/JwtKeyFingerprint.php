<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main\SystemException;

final class JwtKeyFingerprint
{
	public function getFingerprintFromPrivateKey(string $privateKey): string
	{
		$publicKey = $this->extractPublicKey($privateKey);

		return $this->getFingerprintFromPublicKey($publicKey);
	}

	public function getFingerprintFromPublicKey(string $publicKey): string
	{
		$normalizedPublicKey = $this->normalizePublicKey($publicKey);

		return hash('sha256', $normalizedPublicKey);
	}

	private function extractPublicKey(string $privateKey): string
	{
		$privateKeyResource = openssl_pkey_get_private($privateKey);
		if ($privateKeyResource === false)
		{
			throw new SystemException('Failed to read JWT private key');
		}

		$keyDetails = openssl_pkey_get_details($privateKeyResource);
		if ($keyDetails === false || !isset($keyDetails['key']) || !is_string($keyDetails['key']))
		{
			throw new SystemException('Failed to extract JWT public key from private key');
		}

		return $this->normalizePublicKey($keyDetails['key']);
	}

	public function normalizePublicKey(string $publicKey): string
	{
		$publicKeyResource = openssl_pkey_get_public($publicKey);
		if ($publicKeyResource === false)
		{
			throw new SystemException('Failed to read JWT public key');
		}

		$keyDetails = openssl_pkey_get_details($publicKeyResource);
		if ($keyDetails === false || !isset($keyDetails['key']) || !is_string($keyDetails['key']))
		{
			throw new SystemException('Failed to normalize JWT public key');
		}

		return $keyDetails['key'];
	}
}
