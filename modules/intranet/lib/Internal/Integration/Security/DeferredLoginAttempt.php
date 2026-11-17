<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Security;

use Bitrix\Main\Loader;
use Bitrix\Main\Security\Random;
use Bitrix\Security\Mfa\Otp;

/**
 * The login attempt waiting for a second factor. It lives in the kernel session of the client
 * that started the login, so only a request of that very client can name it.
 */
final class DeferredLoginAttempt
{
	private const ID_LENGTH = 32;
	private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/D';
	private const USER_ID_KEY = 'USER_ID';

	/**
	 * The identifier of the attempt is kept among the deferred parameters themselves: that array is built anew
	 * by every login and is dropped the moment the attempt ends, the successful one included, while a code
	 * mistyped in between carries it over untouched.
	 */
	private const ID_KEY = 'INTRANET_ATTEMPT_ID';

	public function getUserId(): int
	{
		return $this->readUserId($this->readParams());
	}

	public function getId(): string
	{
		return $this->readId($this->readParams());
	}

	public function claimId(): string
	{
		$params = $this->readParams();
		if (!is_array($params) || $this->readUserId($params) <= 0)
		{
			return '';
		}

		$id = $this->readId($params);
		if ($id !== '')
		{
			return $id;
		}

		$id = Random::getString(self::ID_LENGTH, true);
		$params[self::ID_KEY] = $id;
		Otp::setDeferredParams($params);

		return $id;
	}

	private function readParams(): ?array
	{
		return Loader::includeModule('security') ? Otp::getDeferredParams() : null;
	}

	private function readUserId(mixed $params): int
	{
		$userId = is_array($params) ? (int)($params[self::USER_ID_KEY] ?? 0) : 0;

		return max(0, $userId);
	}

	private function readId(mixed $params): string
	{
		$id = is_array($params) ? ($params[self::ID_KEY] ?? null) : null;

		return is_string($id) && preg_match(self::ID_PATTERN, $id) === 1 ? $id : '';
	}
}
