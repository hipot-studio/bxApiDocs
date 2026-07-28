<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Auth;

use Bitrix\Main\Application;

/**
 * Guest invite code (16-char alphanumeric).
 * Read from cookie {@see AuthorizationService::COOKIE_INVITE_CODE}.
 */
class InviteCode
{
	protected const PATTERN = '/^[a-zA-Z0-9]{16}$/';

	public function __construct(
		protected string $value,
	)
	{
	}

	public static function createFromRequest(): ?static
	{
		$cookieValue = Application::getInstance()->getContext()->getRequest()
			->getCookieRaw(AuthorizationService::COOKIE_INVITE_CODE);

		if (is_string($cookieValue) && self::isValid($cookieValue))
		{
			return new static($cookieValue);
		}

		return null;
	}

	public static function isValid(string $code): bool
	{
		return (bool)preg_match(self::PATTERN, $code);
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
