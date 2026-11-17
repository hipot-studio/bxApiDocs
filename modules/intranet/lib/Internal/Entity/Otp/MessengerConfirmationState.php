<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\Otp;

use Bitrix\Intranet\Public\Enum\Otp\MessengerConfirmationStatus;
use Bitrix\Main\ArgumentException;

final class MessengerConfirmationState
{
	public const REQUEST_ID_PATTERN = '/^[A-Za-z0-9_-]{16,128}$/D';
	public const SESSION_BINDING_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/D';
	public const CHANNEL_TAG_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/D';

	public function __construct(
		public readonly string $requestId,
		public readonly int $userId,
		public readonly string $sessionBinding,
		public readonly int $expiresAt,
		public readonly MessengerConfirmationStatus $status = MessengerConfirmationStatus::Pending,
		public readonly ?string $channelTag = null,
		public readonly ?int $messageId = null,
		public readonly ?int $confirmedAt = null,
		public readonly ?array $attemptContext = null,
	)
	{
		foreach ($attemptContext ?? [] as $name => $value)
		{
			if (!in_array($name, LoginAttemptContextField::ALL, true) || !is_string($value))
			{
				throw new ArgumentException('Attempt context must contain known string details.', 'attemptContext');
			}
		}

		if (preg_match(self::REQUEST_ID_PATTERN, $requestId) !== 1)
		{
			throw new ArgumentException('Request id must be an opaque Base64URL value.', 'requestId');
		}

		if ($userId <= 0)
		{
			throw new ArgumentException('User id must be positive.', 'userId');
		}

		if (preg_match(self::SESSION_BINDING_PATTERN, $sessionBinding) !== 1)
		{
			throw new ArgumentException('Session binding must be an opaque Base64URL value.', 'sessionBinding');
		}

		if ($expiresAt <= 0)
		{
			throw new ArgumentException('Expiration timestamp must be positive.', 'expiresAt');
		}

		if ($channelTag !== null && preg_match(self::CHANNEL_TAG_PATTERN, $channelTag) !== 1)
		{
			throw new ArgumentException('Channel tag must be an opaque Base64URL value.', 'channelTag');
		}

		if ($messageId !== null && $messageId <= 0)
		{
			throw new ArgumentException('Message id must be positive.', 'messageId');
		}

		if ($confirmedAt !== null && $confirmedAt <= 0)
		{
			throw new ArgumentException('Confirmation timestamp must be positive.', 'confirmedAt');
		}
	}

	public function isExpiredAt(int $timestamp): bool
	{
		return $this->expiresAt <= $timestamp;
	}

	public function isConfirmationFreshAt(int $timestamp, int $lifetimeSeconds): bool
	{
		if ($this->confirmedAt === null || $lifetimeSeconds <= 0)
		{
			return false;
		}

		return $timestamp - $this->confirmedAt < $lifetimeSeconds;
	}

	public function isBoundTo(string $sessionBinding): bool
	{
		return hash_equals($this->sessionBinding, $sessionBinding);
	}

	public function withStatus(MessengerConfirmationStatus $status): self
	{
		return new self(
			$this->requestId,
			$this->userId,
			$this->sessionBinding,
			$this->expiresAt,
			$status,
			$this->channelTag,
			$this->messageId,
			$this->confirmedAt,
			$this->attemptContext,
		);
	}

	public function withConfirmationAt(int $confirmedAt): self
	{
		return new self(
			$this->requestId,
			$this->userId,
			$this->sessionBinding,
			$this->expiresAt,
			MessengerConfirmationStatus::Confirmed,
			$this->channelTag,
			$this->messageId,
			$confirmedAt,
			$this->attemptContext,
		);
	}

	public function withMessageId(int $messageId): self
	{
		return new self(
			$this->requestId,
			$this->userId,
			$this->sessionBinding,
			$this->expiresAt,
			$this->status,
			$this->channelTag,
			$messageId,
			$this->confirmedAt,
			$this->attemptContext,
		);
	}
}
