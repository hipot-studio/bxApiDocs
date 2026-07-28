<?php
declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess;

use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Web\Cookie;

class UserQuickAccessTokenManager
{
	public const COOKIE_NAME = 'DTOKEN';
	public const DEFAULT_COOKIE_TTL = 3600 * 8;
	public const DEFAULT_TOKEN_LENGTH = 16;

	private ?string $userToken = null;
	private ?Signer $signer = null;

	/**
	 * @param HttpRequest $httpRequest
	 * @param HttpResponse $httpResponse
	 * @param string|null $signerKey
	 * @throws ArgumentTypeException
	 */
	public function __construct(
		private readonly HttpRequest $httpRequest,
		private readonly HttpResponse $httpResponse,
		private readonly ?string $signerKey,
	)
	{
		$userToken = $this->retrieveUserToken();
		if ($userToken)
		{
			$this->userToken = $userToken;
		}
	}

	/**
	 * Retrieve and validate user token from cookie
	 *
	 * @return string|null Raw user token or null if not found or invalid
	 * @throws ArgumentTypeException
	 */
	private function retrieveUserToken(): ?string
	{
		$signedToken = $this->httpRequest->getCookie(self::COOKIE_NAME);
		if (!$signedToken)
		{
			return null;
		}

		try
		{
			$rawToken = $this->getSigner()->unsign($signedToken);
		}
		catch (BadSignatureException)
		{
			return null;
		}

		if (!\is_string($rawToken) || mb_strlen($rawToken) !== self::DEFAULT_TOKEN_LENGTH)
		{
			return null;
		}

		return $rawToken;
	}

	/**
	 * Get or create a Signer instance
	 *
	 * @return Signer
	 * @throws ArgumentTypeException
	 */
	private function getSigner(): Signer
	{
		if ($this->signer === null)
		{
			$this->signer = new Signer();
			$this->signer->setKey(hash('sha512', $this->signerKey));
		}

		return $this->signer;
	}

	private function deleteCookieWithUserToken(): void
	{
		$cookie = $this->generateCookieWithUserToken(token: '', expires: time() - 3600);

		$this->httpResponse->addCookie($cookie, true, false);
	}

	/**
	 * Generate a cookie with the signed user token
	 *
	 * @param string $token The raw token to sign and set
	 * @return Cookie Cookie instance
	 */
	private function generateCookieWithUserToken(string $token, ?int $expires = null): Cookie
	{
		$secure = (Option::get('main', 'use_secure_password_cookies', 'N') === 'Y' && $this->httpRequest->isHttps());

		if ($expires === null)
		{
			$expires = time() + self::DEFAULT_COOKIE_TTL;
		}

		$cookie = new Cookie(self::COOKIE_NAME, $token, $expires);
		$cookie
			->setHttpOnly(true)
			->setSecure($secure)
		;

		return $cookie;
	}

	/**
	 * Ensures the user token exists, creating it if necessary
	 *
	 * @return void
	 * @throws ArgumentTypeException
	 */
	public function ensureUserToken(): void
	{
		if (!isset($this->userToken))
		{
			[$this->userToken, $signedToken] = $this->generateUserToken();
			$cookie = $this->generateCookieWithUserToken($signedToken);
			$this->httpResponse->addCookie($cookie);
		}
	}

	public function clearUserToken(): void
	{
		$this->userToken = null;
		$this->deleteCookieWithUserToken();
	}

	/**
	 * Generate a secure user token
	 *
	 * @return array{string, string} Generated raw token and its signed value
	 * @throws ArgumentTypeException
	 */
	private function generateUserToken(): array
	{
		$randValue = Random::getString(self::DEFAULT_TOKEN_LENGTH, true);
		$signedValue = $this->getSigner()->sign($randValue);

		return [$randValue, $signedValue];
	}

	public function getUserToken(): ?string
	{
		return $this->userToken;
	}

	public function isUserTokenSet(): bool
	{
		return $this->userToken !== null;
	}
}
