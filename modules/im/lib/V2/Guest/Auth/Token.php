<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Auth;

use Bitrix\Main\Application;

/**
 * Guest authentication token (32-char alphanumeric).
 * Read from cookie {@see AuthorizationService::COOKIE_HASH}.
 */
class Token
{
	protected const HASH_PATTERN = '/^[a-zA-Z0-9]{32}$/';

	public function __construct(
		protected string $value,
	)
	{
		if (!self::isValid($value))
		{
			throw new \InvalidArgumentException("Invalid guest token format: value must be a 32-char alphanumeric string");
		}
	}

	public static function createFromRequest(): ?static
	{
		$cookieToken = Application::getInstance()->getContext()->getRequest()
			->getCookieRaw(AuthorizationService::COOKIE_HASH);

		if (is_string($cookieToken) && self::isValid($cookieToken))
		{
			return new static($cookieToken);
		}

		return null;
	}

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
