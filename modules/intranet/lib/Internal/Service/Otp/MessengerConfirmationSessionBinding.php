<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Internal\Entity\Otp\MessengerConfirmationState;
use Bitrix\Main\Application;
use Bitrix\Main\Security\Random;

/**
 * Binds a messenger confirmation request to the login attempt that created it. The binding is leased to one
 * attempt and names it, so a session that outlives the attempt keeps nothing able to prove ownership of its
 * requests. Every transport owns its own kernel session key and shares the value format.
 */
final class MessengerConfirmationSessionBinding
{
	public const LENGTH = 32;

	private const VALUE_FIELD = 'binding';
	private const USER_FIELD = 'userId';
	private const ATTEMPT_FIELD = 'attemptId';

	public function __construct(private readonly string $sessionKey)
	{
	}

	public function read(int $userId, string $attemptId): string
	{
		$lease = $this->readLease();

		return $lease !== null && $this->isLeasedTo($lease, $userId, $attemptId)
			? $lease[self::VALUE_FIELD]
			: ''
		;
	}

	public function issue(int $userId, string $attemptId): string
	{
		$binding = $this->read($userId, $attemptId);
		if ($binding !== '')
		{
			return $binding;
		}

		if ($userId <= 0 || $attemptId === '')
		{
			return '';
		}

		$binding = Random::getString(self::LENGTH, true);
		Application::getInstance()->getKernelSession()[$this->sessionKey] = [
			self::VALUE_FIELD => $binding,
			self::USER_FIELD => $userId,
			self::ATTEMPT_FIELD => $attemptId,
		];

		return $binding;
	}

	/**
	 * A value left by the previous release names no attempt, so it is no lease of the running one: the session
	 * survives a deployment, and such a value is replaced by the next request of its own client.
	 */
	private function readLease(): ?array
	{
		$lease = Application::getInstance()->getKernelSession()[$this->sessionKey] ?? null;

		if (
			!is_array($lease)
			|| !is_string($lease[self::VALUE_FIELD] ?? null)
			|| !is_int($lease[self::USER_FIELD] ?? null)
			|| !is_string($lease[self::ATTEMPT_FIELD] ?? null)
		)
		{
			return null;
		}

		return preg_match(MessengerConfirmationState::SESSION_BINDING_PATTERN, $lease[self::VALUE_FIELD]) === 1
			? $lease
			: null
		;
	}

	private function isLeasedTo(array $lease, int $userId, string $attemptId): bool
	{
		return $userId > 0
			&& $attemptId !== ''
			&& $lease[self::USER_FIELD] === $userId
			&& hash_equals($lease[self::ATTEMPT_FIELD], $attemptId)
		;
	}
}
