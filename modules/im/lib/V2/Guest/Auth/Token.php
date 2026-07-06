<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Auth;

use Bitrix\Main\Application;

/**
 * Value object representing a guest authentication token.
 *
 * Encapsulates 32-char alphanumeric hash used for guest identification.
 * Provides factory method for extracting token from HTTP request.
 *
 * Sources (in priority order):
 * 1. Header: X-Im-Guest-Token
 * 2. Cookie: BITRIX_IM_GUEST_HASH
 */
class Token
{
	public const HEADER = 'X-Im-Guest-Token';

	protected const HASH_PATTERN = '/^[a-zA-Z0-9]{32}$/';

	public function __construct(
		protected string $value,
	)
	{
	}

	/**
	 * Extract token from the current HTTP request.
	 *
	 * @return static|null Token instance or null if not found in request
	 */
	public static function createFromRequest(): ?static
	{
		$request = Application::getInstance()->getContext()->getRequest();

		// 1. Header
		$headerToken = $request->getHeader(self::HEADER);
		if (is_string($headerToken) && self::isValid($headerToken))
		{
			return new static($headerToken);
		}

		// 2. Cookie
		$cookieToken = $request->getCookieRaw(AuthorizationService::COOKIE_HASH);
		if (is_string($cookieToken) && self::isValid($cookieToken))
		{
			return new static($cookieToken);
		}

		return null;
	}

	/**
	 * Validate token format (32-char alphanumeric string).
	 */
	public static function isValid(string $token): bool
	{
		return (bool)preg_match(self::HASH_PATTERN, $token);
	}

	public function getValue(): string
	{
		return $this->value;
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
