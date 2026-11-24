<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\Otp;

use Bitrix\Intranet\Internal\Enum\Otp\PhoneConfirmationScenario;
use Bitrix\Intranet\Internal\Enum\Otp\PhoneConfirmationTerminalStatus;
use Bitrix\Intranet\Public\Contract\Otp\ConfirmationCallChannelInterface;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\InvalidOperationException;

final class ConfirmationCallState
{
	public const SESSION_BINDING_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/D';

	public function __construct(
		public readonly PhoneConfirmationScenario $scenario,
		public readonly string $sessionBinding,
		public readonly int $userId,
		public readonly string $phoneE164,
		public readonly int $phoneRevision,
		public readonly string $confirmationCallId,
		public readonly int $expiresAt,
		public readonly ?PhoneConfirmationTerminalStatus $terminalStatus = null,
	)
	{
		if (preg_match(self::SESSION_BINDING_PATTERN, $sessionBinding) !== 1)
		{
			throw new ArgumentException(
				'Session binding must be a non-empty opaque Base64URL value.',
				'sessionBinding',
			);
		}

		if ($userId <= 0)
		{
			throw new ArgumentException('User id must be positive.', 'userId');
		}

		if (preg_match(ConfirmationCallChannelInterface::PHONE_PATTERN, $phoneE164) !== 1)
		{
			throw new ArgumentException('Phone must be a non-empty E.164 value.', 'phoneE164');
		}

		if ($phoneRevision <= 0)
		{
			throw new ArgumentException('Phone revision must be positive.', 'phoneRevision');
		}

		if (preg_match(ConfirmationCallChannelInterface::CONFIRMATION_CALL_ID_PATTERN, $confirmationCallId) !== 1)
		{
			throw new ArgumentException(
				'Confirmation call id must be a 20-character Base64URL value.',
				'confirmationCallId',
			);
		}

		if ($expiresAt <= 0)
		{
			throw new ArgumentException('Expiration timestamp must be positive.', 'expiresAt');
		}
	}

	public function isTerminal(): bool
	{
		return $this->terminalStatus !== null;
	}

	public function isExpiredAt(int $timestamp): bool
	{
		return $this->expiresAt <= $timestamp;
	}

	public function isBoundTo(
		PhoneConfirmationScenario $scenario,
		string $sessionBinding,
		int $userId,
		int $phoneRevision,
	): bool
	{
		return $this->scenario === $scenario
			&& hash_equals($this->sessionBinding, $sessionBinding)
			&& $this->userId === $userId
			&& $this->phoneRevision === $phoneRevision
		;
	}

	public function hasConfirmationCallId(string $confirmationCallId): bool
	{
		return hash_equals($this->confirmationCallId, $confirmationCallId);
	}

	public function close(PhoneConfirmationTerminalStatus $terminalStatus): self
	{
		if ($this->isTerminal())
		{
			throw new InvalidOperationException('Confirmation call state is already closed.');
		}

		return new self(
			$this->scenario,
			$this->sessionBinding,
			$this->userId,
			$this->phoneE164,
			$this->phoneRevision,
			$this->confirmationCallId,
			$this->expiresAt,
			$terminalStatus,
		);
	}
}
