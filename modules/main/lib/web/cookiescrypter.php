<?php

namespace Bitrix\Main\Web;

use Bitrix\Main\Config;
use Bitrix\Main\Security\Cipher;
use Bitrix\Main\Security\SecurityException;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Session\KernelSession;
use Bitrix\Main\SystemException;

/**
 * Class CookiesCrypter
 * Designed to encrypt and decrypt cookies.
 */
final class CookiesCrypter
{
	public const COOKIE_MAX_SIZE = 4096;
	public const COOKIE_RESERVED_SUFFIX_BYTES = 3;

	private const SIGN_PREFIX = '-crpt-';
	private const CIPHER_KEY_SUFFIX = 'cookiecrypter';
	private const COOKIE_VERSION = 'v2';
	private const COOKIE_VERSION_SEPARATOR = ':';
	private const SIGNATURE_SALT = 'cookies_crypter_v2';

	protected ?string $cipherKey;
	protected Cipher $cipher;
	protected Signer $signer;

	public function __construct()
	{
	}

	/**
	 * Builds cipher object.
	 * @return $this
	 * @throws SystemException
	 */
	protected function buildCipher(): self
	{
		if (isset($this->cipher))
		{
			return $this;
		}

		$configuration = Config\Configuration::getInstance();

		$this->cipher = new Cipher();
		$this->cipherKey = $configuration->get('crypto')['crypto_key'] ?? null;
		if (!$this->cipherKey)
		{
			throw new SystemException('There is no crypto[crypto_key] in .settings.php. Generate it.');
		}
		$this->cipherKey = $this->prependSuffixToKey($this->cipherKey);

		return $this;
	}

	/**
	 * Builds signer object.
	 * @return $this
	 */
	protected function buildSigner(): self
	{
		if (isset($this->signer))
		{
			return $this;
		}

		$this->signer = new Signer();

		return $this;
	}

	/**
	 * Prepends suffix to key.
	 * @param string $key Key to prepend suffix to.
	 * @return string
	 */
	protected function prependSuffixToKey(string $key): string
	{
		return $key . self::CIPHER_KEY_SUFFIX;
	}

	/**
	 * Packs and encrypts cookie.
	 * @param CryptoCookie $cookie Cookie to encrypt.
	 * @return iterable|Cookie[]
	 * @throws SecurityException
	 * @throws SystemException
	 */
	public function encrypt(CryptoCookie $cookie): iterable
	{
		$result = [];
		$encryptedValue = $this->encryptValue($cookie->getValue());
		foreach ($this->packCookie($cookie, $encryptedValue) as $partCookie)
		{
			$result[] = $partCookie;
		}

		return $result;
	}

	/**
	 * Decrypts cookie if needed.
	 * @param string $name Cookie name.
	 * @param string $value Encrypted cookie value.
	 * @param iterable $cookies Cookies to decrypt.
	 * @return string
	 */
	public function decrypt(string $name, string $value, iterable $cookies): string
	{
		if (!$this->shouldDecrypt($name, $value))
		{
			return $value;
		}

		try
		{
			return $this->unpackCookie($value, $cookies);
		}
		catch (SecurityException)
		{
			//just skip cookies which we can't decrypt.
		}

		return '';
	}

	/**
	 * Packs cookie into several parts to fit into cookie size limit.
	 * @param CryptoCookie $cookie Cookie to pack.
	 * @param string       $encryptedValue Encrypted cookie value.
	 * @return iterable|Cookie[]
	 */
	protected function packCookie(CryptoCookie $cookie, string $encryptedValue): iterable
	{
		$length = \strlen($encryptedValue);
		$maxContentLength = self::COOKIE_MAX_SIZE - self::COOKIE_RESERVED_SUFFIX_BYTES - \strlen($cookie->getName());

		$i = 0;
		$parts = ($length / $maxContentLength);
		$pack = [];
		do
		{
			$startPosition = $i * $maxContentLength;
			$partCookie = new Cookie("{$cookie->getName()}_{$i}", substr($encryptedValue, $startPosition, $maxContentLength));
			$cookie->copyAttributesTo($partCookie);
			$pack["{$cookie->getOriginalName()}_{$i}"] = $partCookie;

			$i++;
		}
		while($parts > $i);

		$mainCookie = new Cookie($cookie->getName(), $this->prependSign(implode(',', array_keys($pack))));
		$cookie->copyAttributesTo($mainCookie);

		array_unshift($pack, $mainCookie);

		return $pack;
	}

	/**
	 * Unpacks cookie from several parts.
	 * @param string $mainCookie Main cookie value.
	 * @param iterable $cookies Cookies to decrypt.
	 * @return string
	 */
	protected function unpackCookie(string $mainCookie, iterable $cookies): string
	{
		$mainCookie = $this->removeSign($mainCookie);
		$packedNames = array_flip(array_filter(explode(',', $mainCookie)));
		$parts = [];

		foreach ($cookies as $name => $value)
		{
			if (!isset($packedNames[$name]))
			{
				continue;
			}

			$parts[$packedNames[$name]] = $value;
			if (\count($parts) === \count($packedNames))
			{
				break;
			}
		}
		ksort($parts);
		$encryptedValue = implode('', $parts);

		return $this->decryptValue($encryptedValue);
	}

	/**
	 * Encrypts value.
	 * Also compresses value if possible.
	 * @param string $value Value to encrypt.
	 * @return string
	 * @throws SecurityException
	 * @throws SystemException
	 */
	protected function encryptValue(string $value): string
	{
		$this->buildCipher();
		if (\function_exists('gzencode'))
		{
			$value = gzencode($value);
		}
		$value = self::COOKIE_VERSION . self::COOKIE_VERSION_SEPARATOR . $value;
		$value = $this->signValue($value);

		return $this->encodeUrlSafeB64($this->cipher->encrypt($value, $this->getCipherKey()));
	}

	/**
	 * Decrypts value.
	 * @param string $value Value to decrypt.
	 * @return string
	 * @throws SecurityException
	 * @throws SystemException
	 */
	protected function decryptValue(string $value): string
	{
		$this->buildCipher();

		$value = $this->cipher->decrypt($this->decodeUrlSafeB64($value), $this->getCipherKey());
		$value = $this->verifyValue($value);
		$value = $this->removeVersion($value);
		if (\function_exists('gzdecode'))
		{
			$decodedValue = gzdecode($value);
			if ($decodedValue === false)
			{
				throw new SecurityException('Cookie decompression failed.');
			}

			$value = $decodedValue;
		}

		return $value;
	}

	/**
	 * Signs compressed cookie value.
	 * @param string $value Value to sign.
	 * @return string
	 */
	protected function signValue(string $value): string
	{
		$this->buildSigner();

		return $this->signer->pack([
			$value,
			$this->signer->getSignature($value, self::SIGNATURE_SALT),
		]);
	}

	/**
	 * Verifies and extracts compressed cookie value.
	 * @param string $value Signed value.
	 * @return string
	 * @throws SecurityException
	 */
	protected function verifyValue(string $value): string
	{
		$this->buildSigner();

		$separator = $this->signer->getSeparator();
		$signaturePosition = strrpos($value, $separator);
		if ($signaturePosition === false)
		{
			throw new SecurityException('Cookie signature is missing.');
		}

		$unsignedValue = substr($value, 0, $signaturePosition);
		$signature = substr($value, $signaturePosition + strlen($separator));
		try
		{
			$isValid = $this->signer->validate($unsignedValue, $signature, self::SIGNATURE_SALT);
		}
		catch (BadSignatureException)
		{
			$isValid = false;
		}

		if (!$isValid)
		{
			throw new SecurityException('Cookie signature is invalid.');
		}

		return $unsignedValue;
	}

	/**
	 * Removes and validates cookie version.
	 * @param string $value Versioned value.
	 * @return string
	 * @throws SecurityException
	 */
	protected function removeVersion(string $value): string
	{
		$versionPrefix = self::COOKIE_VERSION . self::COOKIE_VERSION_SEPARATOR;
		if (!str_starts_with($value, $versionPrefix))
		{
			throw new SecurityException('Unsupported cookie version.');
		}

		return substr($value, strlen($versionPrefix));
	}

	/**
	 * Decodes url safe base64.
	 * @param string $input Input string.
	 * @return string
	 */
	private function decodeUrlSafeB64(string $input): string
	{
		$padLength = 4 - \strlen($input) % 4;
		$input .= str_repeat('=', $padLength);

		return base64_decode(strtr($input, '-_', '+/')) ?: '';
	}

	/**
	 * Encodes url safe base64 to avoid issues with cookie values.
	 * @param string $input Input string.
	 * @return string
	 */
	private function encodeUrlSafeB64(string $input): string
	{
		return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
	}

	/**
	 * Checks if cookie should be encrypted.
	 * @param Cookie $cookie Cookie to check.
	 * @return bool
	 */
	public function shouldEncrypt(Cookie $cookie): bool
	{
		return $cookie instanceof CryptoCookie;
	}

	/**
	 * Checks if cookie should be decrypted.
	 * @param string $cookieName Cookie name.
	 * @param string $cookieValue Cookie value.
	 * @return bool
	 */
	public function shouldDecrypt(string $cookieName, string $cookieValue): bool
	{
		if ($cookieName === KernelSession::COOKIE_NAME)
		{
			return true;
		}

		return str_starts_with($cookieValue, self::SIGN_PREFIX);
	}

	/**
	 * Prepends sign to value.
	 * @param string $value Value to prepend sign to.
	 * @return string
	 */
	protected function prependSign(string $value): string
	{
		return self::SIGN_PREFIX . $value;
	}

	/**
	 * Removes sign prefix from value.
	 * @param string $value Value to remove sign from.
	 * @return string
	 */
	protected function removeSign(string $value): string
	{
		return substr($value, \strlen(self::SIGN_PREFIX));
	}

	/**
	 * Returns cipher key.
	 * @return string
	 */
	public function getCipherKey(): string
	{
		return $this->cipherKey;
	}
}
