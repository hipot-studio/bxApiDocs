<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Repository\Otp;

use Bitrix\Intranet\Internal\Entity\Otp\ConfirmationCallState;
use Bitrix\Intranet\Internal\Enum\Otp\PhoneConfirmationScenario;
use Bitrix\Intranet\Internal\Enum\Otp\PhoneConfirmationTerminalStatus;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;

final class ConfirmationCallStateRepository
{
	private const KEY_PREFIX = 'intranet.otp.confirmation_call.';
	private const MAX_TTL_SECONDS = 3600;

	private StorageInterface $storage;
	private \Closure $clock;

	public function __construct(?StorageInterface $storage = null, ?\Closure $clock = null)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
		$this->clock = $clock ?? static fn(): int => time();
	}

	public function find(PhoneConfirmationScenario $scenario, string $sessionBinding): ?ConfirmationCallState
	{
		$value = $this->storage->get($this->getStorageKey($scenario, $sessionBinding));
		if (!is_array($value))
		{
			return null;
		}

		$state = $this->restore($scenario, $sessionBinding, $value);
		if ($state === null || $state->isExpiredAt($this->getCurrentTime()))
		{
			return null;
		}

		return $state;
	}

	public function save(ConfirmationCallState $state): bool
	{
		$ttl = $this->calculateTtl($state->expiresAt);
		if ($ttl <= 0)
		{
			return false;
		}

		$this->storage->set(
			$this->getStorageKey($state->scenario, $state->sessionBinding),
			[
				'userId' => $state->userId,
				'phoneE164' => $state->phoneE164,
				'phoneRevision' => $state->phoneRevision,
				'confirmationCallId' => $state->confirmationCallId,
				'expiresAt' => $state->expiresAt,
				'terminalStatus' => $state->terminalStatus?->value,
			],
			$ttl,
		);

		return true;
	}

	public function delete(PhoneConfirmationScenario $scenario, string $sessionBinding): void
	{
		$this->storage->delete($this->getStorageKey($scenario, $sessionBinding));
	}

	private function restore(
		PhoneConfirmationScenario $scenario,
		string $sessionBinding,
		array $value,
	): ?ConfirmationCallState
	{
		if (
			!isset($value['userId'], $value['phoneE164'], $value['phoneRevision'])
			|| !isset($value['confirmationCallId'], $value['expiresAt'])
			|| !is_int($value['userId'])
			|| !is_string($value['phoneE164'])
			|| !is_int($value['phoneRevision'])
			|| !is_string($value['confirmationCallId'])
			|| !is_int($value['expiresAt'])
		)
		{
			return null;
		}

		$terminalStatus = null;
		if (isset($value['terminalStatus']))
		{
			if (!is_string($value['terminalStatus']))
			{
				return null;
			}

			$terminalStatus = PhoneConfirmationTerminalStatus::tryFrom($value['terminalStatus']);
			if ($terminalStatus === null)
			{
				return null;
			}
		}

		try
		{
			return new ConfirmationCallState(
				$scenario,
				$sessionBinding,
				$value['userId'],
				$value['phoneE164'],
				$value['phoneRevision'],
				$value['confirmationCallId'],
				$value['expiresAt'],
				$terminalStatus,
			);
		}
		catch (ArgumentException)
		{
			return null;
		}
	}

	private function calculateTtl(int $expiresAt): int
	{
		return min($expiresAt - $this->getCurrentTime(), self::MAX_TTL_SECONDS);
	}

	private function getCurrentTime(): int
	{
		return ($this->clock)();
	}

	private function getStorageKey(PhoneConfirmationScenario $scenario, string $sessionBinding): string
	{
		return self::KEY_PREFIX . $scenario->value . '.' . hash('sha256', $sessionBinding);
	}
}
