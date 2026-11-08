<?php

namespace Bitrix\Main\Security\QuickAccess;

use Bitrix\Main\Security\Cipher;
use Bitrix\Main\Security\SecurityException;

class DeterministicCipher extends Cipher
{
	private const VERSION_MARKER = 'v2';
	private const MAC_PURPOSE = 'quickway_deterministic_cipher_v2';
	private const MAC_LENGTH = 32;

	private $ivSalt = '';

	public function setIvSalt(string $ivSalt): void
	{
		$this->ivSalt = $ivSalt;
	}

	private function generateIv($data, $key): string
	{
		return substr(openssl_digest($data . $this->ivSalt . $key, $this->hashAlgorithm, true), 0, $this->ivLength);
	}

	private function calculateMac(string $data, string $key): string
	{
		$macKey = hash_hmac('sha256', self::MAC_PURPOSE, $key, true);

		return hash_hmac('sha256', $data, $macKey, true);
	}

	public function encrypt($data, $key): string
	{
		$iv = $this->generateIv($data, $key);
		$keyHash = openssl_digest($iv . $key, $this->hashAlgorithm, true);

		if ($this->calculateHash)
		{
			$dataHash = openssl_digest($data, $this->hashAlgorithm, true);
			$data = $dataHash . $data;
		}

		$encrypted = openssl_encrypt($data, $this->cipherAlgorithm, $keyHash, OPENSSL_RAW_DATA, $iv);
		if ($encrypted === false)
		{
			throw new SecurityException("Encryption failed: " . openssl_error_string());
		}

		$payload = self::VERSION_MARKER . $iv . $encrypted;

		return $payload . $this->calculateMac($payload, $key);
	}

	public function decrypt($data, $key): string
	{
		$versionLength = strlen(self::VERSION_MARKER);
		if (strlen($data) <= $versionLength + $this->ivLength + self::MAC_LENGTH)
		{
			throw new SecurityException('The encrypted data is too short.');
		}

		if (substr($data, 0, $versionLength) !== self::VERSION_MARKER)
		{
			throw new SecurityException('The encrypted data version is unsupported.');
		}

		$payloadLength = strlen($data) - self::MAC_LENGTH;
		$payload = substr($data, 0, $payloadLength);
		$mac = substr($data, $payloadLength);
		if (!hash_equals($this->calculateMac($payload, $key), $mac))
		{
			throw new SecurityException('The encrypted data signature is incorrect.');
		}

		$iv = substr($payload, $versionLength, $this->ivLength);
		$raw = substr($payload, $versionLength + $this->ivLength);
		$keyHash = openssl_digest($iv . $key, $this->hashAlgorithm, true);
		$result = openssl_decrypt($raw, $this->cipherAlgorithm, $keyHash, OPENSSL_RAW_DATA, $iv);
		if ($result === false)
		{
			throw new SecurityException("Decryption failed: " . openssl_error_string());
		}

		if ($this->calculateHash)
		{
			$length = strlen($keyHash);
			if (strlen($result) < $length)
			{
				throw new SecurityException('The encrypted data is too short.');
			}

			$hash = substr($result, 0, $length);
			$result = substr($result, $length);
			$dataHash = openssl_digest($result, $this->hashAlgorithm, true);
			if ($dataHash !== $hash)
			{
				throw new SecurityException('The hash is incorrect: the data was corrupted or a wrong key was supplied.');
			}
		}

		return $result;
	}
}
