<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Repository\Otp;

use Bitrix\Intranet\Internal\Entity\Otp\MessengerConfirmationState;
use Bitrix\Intranet\Public\Enum\Otp\MessengerConfirmationStatus;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;

final class MessengerConfirmationStateRepository
{
	/**
	 * The state outlives the request it describes: the transition into Expired happens after expiresAt, and a
	 * client pressing late is owed that answer instead of a request that has silently disappeared.
	 */
	public const RETENTION_AFTER_EXPIRATION_SECONDS = 600;

	private const REQUEST_KEY_PREFIX = 'intranet.otp.messenger_confirmation.request.';
	private const SESSION_KEY_PREFIX = 'intranet.otp.messenger_confirmation.session.';
	private const MAX_TTL_SECONDS = 3600;

	private StorageInterface $storage;

	public function __construct(?StorageInterface $storage = null)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	/**
	 * The longest request window the storage is able to outlive, retention included. A window above it would
	 * promise the client a moment the state no longer answers at.
	 */
	public static function getMaxRequestWindowSeconds(): int
	{
		return self::MAX_TTL_SECONDS - self::RETENTION_AFTER_EXPIRATION_SECONDS;
	}

	public function find(string $requestId): ?MessengerConfirmationState
	{
		if (preg_match(MessengerConfirmationState::REQUEST_ID_PATTERN, $requestId) !== 1)
		{
			return null;
		}

		$value = $this->storage->get($this->getRequestKey($requestId));

		return is_array($value) ? $this->restore($requestId, $value) : null;
	}

	public function readFromMaster(string $requestId): ?MessengerConfirmationState
	{
		return $this->readBoundToMaster(fn(): ?MessengerConfirmationState => $this->find($requestId));
	}

	public function findBySessionBinding(string $sessionBinding): ?MessengerConfirmationState
	{
		$requestId = $this->readSessionPointer($sessionBinding);
		if ($requestId === null)
		{
			return null;
		}

		$state = $this->find($requestId);

		return $state !== null && $state->isBoundTo($sessionBinding) ? $state : null;
	}

	public function readBySessionBindingFromMaster(string $sessionBinding): ?MessengerConfirmationState
	{
		return $this->readBoundToMaster(
			fn(): ?MessengerConfirmationState => $this->findBySessionBinding($sessionBinding),
		);
	}

	public function isCurrentRequestForSession(string $requestId, string $sessionBinding): bool
	{
		return $this->readBoundToMaster(
			fn(): bool => $this->readSessionPointer($sessionBinding) === $requestId,
		);
	}

	/**
	 * @param bool $bindSession The session pointer is rewritten only when the request behind the login
	 *                          attempt actually changes; a save of the same request leaves it alone.
	 */
	public function save(MessengerConfirmationState $state, bool $bindSession = true): bool
	{
		$ttl = $this->calculateTtl($state->expiresAt);
		if ($ttl <= 0)
		{
			return false;
		}

		$saved = $this->storage->set(
			$this->getRequestKey($state->requestId),
			[
				'userId' => $state->userId,
				'sessionBinding' => $state->sessionBinding,
				'expiresAt' => $state->expiresAt,
				'status' => $state->status->value,
				'channelTag' => $state->channelTag,
				'messageId' => $state->messageId,
				'confirmedAt' => $state->confirmedAt,
				'attemptContext' => $state->attemptContext,
			],
			$ttl,
		);

		if (!$saved)
		{
			return false;
		}

		if ($bindSession && !$state->status->isTerminal())
		{
			return $this->storage->set(
				$this->getSessionKey($state->sessionBinding),
				[
					'requestId' => $state->requestId,
				],
				$ttl,
			);
		}

		return true;
	}

	private function readSessionPointer(string $sessionBinding): ?string
	{
		if (preg_match(MessengerConfirmationState::SESSION_BINDING_PATTERN, $sessionBinding) !== 1)
		{
			return null;
		}

		$value = $this->storage->get($this->getSessionKey($sessionBinding));
		$requestId = is_array($value) ? ($value['requestId'] ?? null) : null;

		return is_string($requestId) ? $requestId : null;
	}

	private function restore(string $requestId, array $value): ?MessengerConfirmationState
	{
		if (
			!isset($value['userId'], $value['sessionBinding'], $value['expiresAt'], $value['status'])
			|| !is_int($value['userId'])
			|| !is_string($value['sessionBinding'])
			|| !is_int($value['expiresAt'])
			|| !is_string($value['status'])
		)
		{
			return null;
		}

		$status = MessengerConfirmationStatus::tryFrom($value['status']);
		if ($status === null)
		{
			return null;
		}

		$channelTag = $value['channelTag'] ?? null;
		if ($channelTag !== null && !is_string($channelTag))
		{
			return null;
		}

		$messageId = $value['messageId'] ?? null;
		if ($messageId !== null && !is_int($messageId))
		{
			return null;
		}

		$confirmedAt = $value['confirmedAt'] ?? null;
		if ($confirmedAt !== null && !is_int($confirmedAt))
		{
			return null;
		}

		$attemptContext = $value['attemptContext'] ?? null;
		if ($attemptContext !== null && !is_array($attemptContext))
		{
			return null;
		}

		try
		{
			return new MessengerConfirmationState(
				$requestId,
				$value['userId'],
				$value['sessionBinding'],
				$value['expiresAt'],
				$status,
				$channelTag,
				$messageId,
				$confirmedAt,
				$attemptContext,
			);
		}
		catch (ArgumentException)
		{
			return null;
		}
	}

	/** @template T @param \Closure(): T $read @return T */
	private function readBoundToMaster(\Closure $read): mixed
	{
		$connectionPool = Application::getInstance()->getConnectionPool();
		$connectionPool->useMasterOnly(true);
		try
		{
			return $read();
		}
		finally
		{
			$connectionPool->useMasterOnly(false);
		}
	}

	private function calculateTtl(int $expiresAt): int
	{
		return min(
			$expiresAt + self::RETENTION_AFTER_EXPIRATION_SECONDS - time(),
			self::MAX_TTL_SECONDS,
		);
	}

	private function getRequestKey(string $requestId): string
	{
		return self::REQUEST_KEY_PREFIX . hash('sha256', $requestId);
	}

	private function getSessionKey(string $sessionBinding): string
	{
		return self::SESSION_KEY_PREFIX . hash('sha256', $sessionBinding);
	}
}
