<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Notification;

use Bitrix\Main\Web\Json;
use Bitrix\Socialnetwork\V2\Internal\Entity\Notification\NotificationType;
use Throwable;

class NotificationCounterMapCodec
{
	/**
	 * Encodes a map of wire-key => bool overrides to a compact JSON string.
	 * Only entries that differ from the registry defaults are stored;
	 * entries matching the default are omitted to keep the value within 255 chars.
	 *
	 * @param array<string, bool> $overrides wire-key => effective counter flag
	 * @return string JSON string, empty map '{}' when no overrides
	 */
	public function encode(array $overrides): string
	{
		$normalized = [];
		foreach ($overrides as $wireKey => $value)
		{
			if (!is_string($wireKey) || $wireKey === '' || !is_bool($value))
			{
				continue;
			}

			$normalized[$wireKey] = $value;
		}

		ksort($normalized);

		return Json::encode($normalized);
	}

	/**
	 * Decodes a JSON string into a wire-key => bool map.
	 * Unknown wire-keys are silently ignored (defensive).
	 *
	 * @param string $rawValue stored JSON string
	 * @return array<string, bool> wire-key => counter override flag
	 */
	public function decode(string $rawValue): array
	{
		if ($rawValue === '' || $rawValue === '{}' || $rawValue === '[]')
		{
			return [];
		}

		try
		{
			$decoded = Json::decode($rawValue);
		}
		catch (Throwable)
		{
			return [];
		}

		if (!is_array($decoded))
		{
			return [];
		}

		$knownWireKeys = $this->getKnownWireKeys();

		$result = [];
		foreach ($decoded as $wireKey => $value)
		{
			if (!is_string($wireKey) || $wireKey === '' || !is_bool($value))
			{
				continue;
			}

			// Ignore unknown wire-keys defensively
			if (!isset($knownWireKeys[$wireKey]))
			{
				continue;
			}

			$result[$wireKey] = $value;
		}

		return $result;
	}

	/**
	 * @return array<string, true>
	 */
	private function getKnownWireKeys(): array
	{
		$keys = [];
		foreach (NotificationType::cases() as $type)
		{
			$keys[$type->getWireKey()] = true;
		}

		return $keys;
	}
}
