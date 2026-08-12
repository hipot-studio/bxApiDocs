<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;

final class JwtKeyGenerator
{
	private mixed $privateKeyResource;
	private string $privateKeyPEM;
	private string $publicKeyPEM;
	private array $config;

	/**
	 * @throws Main\SystemException
	 */
	public function __construct()
	{
		$this->config = [
			'digest_alg' => 'sha256',
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		];
		$this->generateKeys();
	}

	public function getPrivateKey(): string
	{
		return $this->privateKeyPEM;
	}

	public function getPublicKey(): string
	{
		return $this->publicKeyPEM;
	}

	public function getBase64EncodePrivateKey(): string
	{
		return base64_encode($this->getPrivateKey());
	}

	public function getBase64EncodePublicKey(): string
	{
		return base64_encode($this->getPublicKey());
	}

	/**
	 * @throws Main\SystemException
	 */
	private function generateKeys(): void
	{
		$this->privateKeyResource = openssl_pkey_new($this->config);
		if ($this->privateKeyResource === false)
		{
			throw new Main\SystemException('Failed to generate private key: ' . openssl_error_string());
		}

		$privateKeyPEM = null;
		if (!openssl_pkey_export($this->privateKeyResource, $privateKeyPEM))
		{
			throw new Main\SystemException('Failed to export private key: ' . openssl_error_string());
		}
		$this->privateKeyPEM = (string)$privateKeyPEM;

		$keyDetails = openssl_pkey_get_details($this->privateKeyResource);
		if ($keyDetails === false)
		{
			throw new Main\SystemException('Failed to get key details: ' . openssl_error_string());
		}

		$this->publicKeyPEM = (string)($keyDetails['key'] ?? '');
	}
}
