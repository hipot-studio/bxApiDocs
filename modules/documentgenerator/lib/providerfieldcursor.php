<?php

namespace Bitrix\DocumentGenerator;

use Bitrix\Main\Security\Sign\Signer;

final class ProviderFieldCursor
{
	private const SALT = 'documentgenerator.children.v1';
	private const MAX_LENGTH = 4096;
	private Signer $signer;

	public function __construct(?Signer $signer = null)
	{
		$this->signer = $signer ?? new Signer();
	}

	public function encode(int $position, string $scope): string
	{
		$payload = json_encode(['v' => 1, 'p' => $position, 's' => $scope], JSON_THROW_ON_ERROR);

		return $this->signer->sign(base64_encode($payload), self::SALT);
	}

	public function decode(string $cursor, string $scope, int $childCount): int
	{
		if ($cursor === '' || strlen($cursor) > self::MAX_LENGTH)
		{
			throw new \InvalidArgumentException('Invalid children cursor size.');
		}
		try
		{
			$encoded = $this->signer->unsign($cursor, self::SALT);
			$json = base64_decode($encoded, true);
			if ($json === false)
			{
				throw new \InvalidArgumentException('Invalid children cursor encoding.');
			}
			$payload = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
		}
		catch (\Exception $exception)
		{
			throw new \InvalidArgumentException('Invalid children cursor.', 0, $exception);
		}
		if (
			!is_array($payload) || array_keys($payload) !== ['v', 'p', 's']
			|| $payload['v'] !== 1 || !is_int($payload['p']) || $payload['p'] < 0
			|| !is_string($payload['s'])
			|| preg_match('/^[a-f0-9]{64}$/D', $payload['s']) !== 1
		)
		{
			throw new \InvalidArgumentException('Invalid children cursor payload.');
		}
		if (!hash_equals($scope, $payload['s']))
		{
			throw new \RuntimeException('CHILDREN_CURSOR_STALE/SCOPE_MISMATCH');
		}

		if ($payload['p'] > $childCount)
		{
			throw new \InvalidArgumentException('Invalid children cursor position.');
		}

		return $payload['p'];
	}

	public static function fingerprint(mixed $value): string
	{
		$hash = hash_init('sha256');
		$active = [];
		self::hashValue($hash, $value, $active);

		return hash_final($hash);
	}

	private static function hashValue(\HashContext $hash, mixed &$value, array &$active): void
	{
		if (is_array($value))
		{
			$reference = [&$value];
			$id = \ReflectionReference::fromArrayElement($reference, 0)->getId();
			if (isset($active[$id]))
			{
				throw new \InvalidArgumentException('Cyclic children cursor metadata.');
			}
			$active[$id] = true;
			$list = array_is_list($value);
			hash_update($hash, ($list ? 'l' : 'a') . count($value) . ':');
			$keys = array_keys($value);
			if (!$list)
			{
				sort($keys, SORT_STRING);
			}
			foreach ($keys as $key)
			{
				self::hashValue($hash, $key, $active);
				self::hashValue($hash, $value[$key], $active);
			}
			unset($active[$id]);
		}
		elseif ($value === null)
		{
			hash_update($hash, 'n');
		}
		elseif (is_bool($value))
		{
			hash_update($hash, $value ? 'b1' : 'b0');
		}
		elseif (is_int($value))
		{
			hash_update($hash, 'i' . $value . ':');
		}
		elseif (is_float($value) && is_finite($value))
		{
			hash_update($hash, 'f' . bin2hex(pack('E', $value)) . ':');
		}
		elseif (is_string($value))
		{
			hash_update($hash, 's' . strlen($value) . ':' . $value);
		}
		else
		{
			throw new \InvalidArgumentException('Unsupported children cursor metadata.');
		}
	}
}
