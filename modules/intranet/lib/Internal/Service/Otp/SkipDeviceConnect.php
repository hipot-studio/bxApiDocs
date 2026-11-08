<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Entity\User;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\StorageInterface;

final class SkipDeviceConnect
{
	public const CHANNEL_PHONE = 'phone';
	public const CHANNEL_EMAIL = 'email';

	private const CHANNEL_KEY = 'channel';
	private const CHANNELS_KEY = 'channels';
	private const STATE_KEY = 'state';
	private const CHANNEL_CONFIRMED_KEY = 'channelConfirmed';
	private const CONFIRMED_CHANNEL_KEY = 'confirmedChannel';
	private const EXPIRES_AT_KEY = 'expiresAt';
	private const STATE_ACTIVE = 'active';
	private const STATE_COMPLETED = 'completed';
	private const TTL = 1800;
	private int $lockDepth = 0;
	private ?string $activeLockName = null;
	private readonly \Closure $timeProvider;

	public function __construct(
		private readonly StorageInterface $storage,
		private readonly string $portalId,
		?\Closure $timeProvider = null,
	)
	{
		$this->timeProvider = $timeProvider ?? static fn(): int => time();
	}

	public function start(User $user, bool $isPhoneAvailable, bool $isEmailAvailable): ?array
	{
		$channels = [];
		if ($isEmailAvailable)
		{
			$channels[] = self::CHANNEL_EMAIL;
		}
		if ($isPhoneAvailable)
		{
			$channels[] = self::CHANNEL_PHONE;
		}

		return $this->runLocked($user, function () use ($user, $channels): ?array {
			if ($channels === [])
			{
				return null;
			}

			$flowId = bin2hex(random_bytes(16));
			$this->storage->set(
				$this->getStorageKey($user, $flowId),
				[
					self::CHANNELS_KEY => $channels,
					self::STATE_KEY => self::STATE_ACTIVE,
					self::EXPIRES_AT_KEY => $this->getCurrentTime() + self::TTL,
				],
				self::TTL,
			);
			return $this->createStartResult($flowId, $channels);
		});
	}

	public function getChannel(User $user, string $flowId): ?string
	{
		$channels = $this->getAvailableChannels($user, $flowId);

		return $channels[0] ?? null;
	}

	public function getAvailableChannels(User $user, string $flowId): array
	{
		return $this->getAvailableChannelsFromData($this->getData($user, $flowId));
	}

	public function getConfirmedChannel(User $user, string $flowId): ?string
	{
		return $this->getConfirmedChannelFromData($this->getData($user, $flowId));
	}

	public function isCompleted(User $user, string $flowId): bool
	{
		return ($this->getData($user, $flowId)[self::STATE_KEY] ?? null) === self::STATE_COMPLETED;
	}

	public function confirmChannel(User $user, string $flowId, string $channel): void
	{
		$this->runLocked($user, function () use ($user, $flowId, $channel): void {
			$data = $this->getData($user, $flowId);
			if (
				$data === []
				|| ($data[self::STATE_KEY] ?? null) !== self::STATE_ACTIVE
				|| !in_array($channel, $this->getAvailableChannelsFromData($data), true)
			)
			{
				return;
			}

			$data[self::CONFIRMED_CHANNEL_KEY] = $channel;
			unset($data[self::CHANNEL_CONFIRMED_KEY]);
			$this->saveData($user, $flowId, $data);
		});
	}

	public function isChannelConfirmed(User $user, string $flowId): bool
	{
		return $this->getConfirmedChannel($user, $flowId) !== null;
	}

	public function complete(User $user, string $flowId): void
	{
		$this->runLocked($user, function () use ($user, $flowId): void {
			$data = $this->getData($user, $flowId);
			if ($data === [] || ($data[self::STATE_KEY] ?? null) !== self::STATE_ACTIVE)
			{
				return;
			}

			$data[self::STATE_KEY] = self::STATE_COMPLETED;
			$this->saveData($user, $flowId, $data);
		});
	}

	public function cancel(User $user, string $flowId): void
	{
		$this->runLocked($user, function () use ($user, $flowId): void {
			$data = $this->getData($user, $flowId);
			if (($data[self::STATE_KEY] ?? null) === self::STATE_ACTIVE)
			{
				$this->deleteFlow($user, $flowId);
			}
		});
	}

	public function runLocked(User $user, callable $operation): mixed
	{
		$lockName = 'intranet_otp_skip_device_connect_' . sha1($this->getUserStoragePrefix($user));
		if ($this->lockDepth > 0)
		{
			if ($this->activeLockName !== $lockName)
			{
				throw new SkipDeviceConnectLockException('Another skip device connection flow is already locked');
			}

			return $operation();
		}

		$connection = Application::getConnection();
		if (!$connection->lock($lockName, 5))
		{
			throw new SkipDeviceConnectLockException('Unable to lock skip device connection flow');
		}

		$this->lockDepth++;
		$this->activeLockName = $lockName;
		try
		{
			return $operation();
		}
		finally
		{
			$this->lockDepth--;
			$this->activeLockName = null;
			$connection->unlock($lockName);
		}
	}

	private function getData(User $user, string $flowId): array
	{
		if (!preg_match('/^[a-f0-9]{32}$/D', $flowId))
		{
			return [];
		}

		$data = $this->storage->get($this->getStorageKey($user, $flowId), []);
		if (!is_array($data))
		{
			return [];
		}

		$expiresAt = $data[self::EXPIRES_AT_KEY] ?? null;
		if (!is_int($expiresAt) || $expiresAt <= $this->getCurrentTime())
		{
			$this->deleteFlow($user, $flowId);

			return [];
		}

		return $data;
	}

	private function saveData(User $user, string $flowId, array $data): void
	{
		$expiresAt = $data[self::EXPIRES_AT_KEY] ?? null;
		$ttl = is_int($expiresAt) ? $expiresAt - $this->getCurrentTime() : 0;
		if ($ttl <= 0)
		{
			$this->deleteFlow($user, $flowId);

			return;
		}

		$this->storage->set($this->getStorageKey($user, $flowId), $data, $ttl);
	}

	private function getAvailableChannelsFromData(array $data): array
	{
		$channels = $data[self::CHANNELS_KEY] ?? [];
		if (!is_array($channels))
		{
			$channels = [];
		}

		$legacyChannel = $data[self::CHANNEL_KEY] ?? null;
		if ($channels === [] && is_string($legacyChannel))
		{
			$channels[] = $legacyChannel;
		}

		$channels = array_filter(
			$channels,
			static fn(mixed $channel): bool => in_array(
				$channel,
				[self::CHANNEL_PHONE, self::CHANNEL_EMAIL],
				true,
			),
		);

		return array_values(array_unique($channels));
	}

	private function getConfirmedChannelFromData(array $data): ?string
	{
		$channel = $data[self::CONFIRMED_CHANNEL_KEY] ?? null;
		if (!is_string($channel) && ($data[self::CHANNEL_CONFIRMED_KEY] ?? false) === true)
		{
			$channel = $data[self::CHANNEL_KEY] ?? null;
		}

		return in_array($channel, $this->getAvailableChannelsFromData($data), true) ? $channel : null;
	}

	private function createStartResult(string $flowId, array $channels): array
	{
		return [
			'channel' => $channels[0],
			'availableChannels' => $channels,
			'flowId' => $flowId,
		];
	}

	private function deleteFlow(User $user, string $flowId): void
	{
		$this->storage->delete($this->getStorageKey($user, $flowId));
	}

	private function getStorageKey(User $user, string $flowId): string
	{
		return $this->getUserStoragePrefix($user) . '.' . $flowId;
	}

	private function getUserStoragePrefix(User $user): string
	{
		return 'intranet.otp.skip_device_connect.' . sha1($this->portalId) . '.' . (int)$user->getId();
	}

	private function getCurrentTime(): int
	{
		return ($this->timeProvider)();
	}
}
