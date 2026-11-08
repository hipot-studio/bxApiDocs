<?php

namespace Bitrix\Call;

use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Registry of ringing callee devices backed by PersistentStorageInterface.
 *
 * One storage key per (call, user) holds a JSON map { callInstanceId => lastSeenUnixTs }.
 * A device counts as "ringing" while its timestamp is within the freshness window
 * ({@see self::freshnessWindow()}), which spans the whole configurable dial period so a
 * one-shot registration does not expire mid-ring. Stale entries are pruned on every
 * mutation and the key is written with a TTL over the window so orphaned state is evicted.
 */
final class RingingDeviceRegistry
{
	private const KEY_PREFIX = 'call.ringing_devices.';

	/** Upper bound on a client-supplied callInstanceId (a UUID is 36 chars). */
	public const MAX_INSTANCE_ID_LENGTH = 128;

	/** Cap on devices tracked per (call, user); a user realistically has a few. */
	private const MAX_DEVICES_PER_USER = 20;

	/** Slack added over the dial period so clock/network skew never expires a ringing device early (seconds). */
	private const FRESHNESS_MARGIN = 15;

	private readonly StorageInterface $storage;

	public function __construct(?StorageInterface $storage = null)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	/**
	 * Records the device as ringing for the (call, user) pair, refreshing its
	 * timestamp. Idempotent: re-registering the same device only bumps the mark.
	 */
	public function register(int $callId, int $userId, string $instanceId): void
	{
		// Ignore unusable identifiers: empty or implausibly long client values.
		if ($instanceId === '' || strlen($instanceId) > self::MAX_INSTANCE_ID_LENGTH)
		{
			return;
		}

		$key = $this->key($callId, $userId);
		$map = $this->readFreshMap($key);
		$map[$instanceId] = time();

		$this->storage->set($key, $this->capToNewest($map), $this->ttl());
	}

	/**
	 * Drops a single device. When no fresh device remains the key is deleted,
	 * otherwise the surviving devices are written back.
	 *
	 * @return bool whether the user still has at least one fresh ringing device
	 *              after this removal (lets the decline path skip a second read).
	 */
	public function remove(int $callId, int $userId, string $instanceId): bool
	{
		$key = $this->key($callId, $userId);

		$storedMap = $this->storage->get($key);
		if (!is_array($storedMap))
		{
			// No registry entry at all (older client that never registered a
			// device) — there is nothing to drop, so skip the redundant DELETE
			// that hitting the master on every 1-1 decline would otherwise cost.
			return false;
		}

		$threshold = time() - $this->freshnessWindow();
		$map = array_filter($storedMap, static fn ($lastSeen): bool => (int)$lastSeen >= $threshold);
		unset($map[$instanceId]);

		if (empty($map))
		{
			$this->storage->delete($key);

			return false;
		}

		$this->storage->set($key, $map, $this->ttl());

		return true;
	}

	/**
	 * Whether the user still has at least one device seen within the freshness window.
	 */
	public function hasFreshDevicesForUser(int $callId, int $userId): bool
	{
		return !empty($this->readFreshMap($this->key($callId, $userId)));
	}

	/**
	 * Removes the registry keys of every given participant. Called from the
	 * exactly-once finish teardown: the registry is only meaningful while ringing.
	 *
	 * @param int[] $userIds
	 */
	public function deleteForCall(int $callId, array $userIds): void
	{
		$keys = [];
		foreach ($userIds as $userId)
		{
			$keys[] = $this->key($callId, (int)$userId);
		}

		if (!empty($keys))
		{
			$this->storage->deleteMultiple($keys);
		}
	}

	/**
	 * Reads the stored device map and drops entries older than the freshness window.
	 *
	 * @return array<string, int>
	 */
	private function readFreshMap(string $key): array
	{
		$map = $this->storage->get($key, []);
		if (!is_array($map))
		{
			return [];
		}

		$threshold = time() - $this->freshnessWindow();

		return array_filter($map, static fn ($lastSeen): bool => (int)$lastSeen >= $threshold);
	}

	/**
	 * Bounds map growth: keeps at most MAX_DEVICES_PER_USER entries, dropping the
	 * least recently seen so a single user cannot bloat the storage key.
	 *
	 * @param array<string, int> $map
	 * @return array<string, int>
	 */
	private function capToNewest(array $map): array
	{
		if (count($map) <= self::MAX_DEVICES_PER_USER)
		{
			return $map;
		}

		arsort($map); // freshest timestamp first
		return array_slice($map, 0, self::MAX_DEVICES_PER_USER, true);
	}

	private function key(int $callId, int $userId): string
	{
		return self::KEY_PREFIX . $callId . '.' . $userId;
	}

	/**
	 * How long a one-shot device mark stays "ringing", in seconds. Spans the whole
	 * configurable dial period (`call.call_invite_period`, ms) plus a skew margin so
	 * a still-ringing device never expires mid-dial when the period exceeds the
	 * baseline last-seen window; never shorter than that baseline.
	 */
	private function freshnessWindow(): int
	{
		$invitePeriodSeconds = (int)ceil(Settings::getCallInvitePeriod() / 1000);

		return max(CallUser::LAST_SEEN_THRESHOLD, $invitePeriodSeconds + self::FRESHNESS_MARGIN);
	}

	private function ttl(): int
	{
		return 2 * $this->freshnessWindow();
	}
}
