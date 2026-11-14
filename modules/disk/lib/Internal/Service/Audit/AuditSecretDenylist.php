<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use InvalidArgumentException;

final class AuditSecretDenylist
{
	private const ALLOWED_PASSWORD_KEYS = [
		'passwordEnabled' => true,
	];

	private const SECRET_KEY_PATTERN
		= '/(?:url|password|secret|token|hash|signature|signed|cookie|auth|requestBody|fileContent)/i';

	/**
	 * @param string[] $allowedKeys
	 * @param string[]|null $requiredKeys
	 */
	public function filterDetails(array $details, array $allowedKeys, ?array $requiredKeys = null): array
	{
		$allowed = array_fill_keys($allowedKeys, true);
		$filtered = [];

		foreach ($details as $key => $value)
		{
			if (!is_string($key) || $key === '')
			{
				throw new InvalidArgumentException('Audit detail keys must be non-empty strings.');
			}

			if (!isset($allowed[$key]) || $this->isDenied($key))
			{
				continue;
			}

			if (in_array($key, ['oldRights', 'newRights'], true))
			{
				AuditRightsDetails::validateRightsList($value);
			}
			elseif ($value !== null && !is_scalar($value))
			{
				throw new InvalidArgumentException('Audit detail values must be scalar or null.');
			}

			$filtered[$key] = $value;
		}

		foreach ($requiredKeys ?? $allowedKeys as $requiredKey)
		{
			if (!array_key_exists($requiredKey, $filtered))
			{
				throw new InvalidArgumentException("Audit detail {$requiredKey} is required.");
			}
		}

		return $filtered;
	}

	public function isDenied(string $key): bool
	{
		if (isset(self::ALLOWED_PASSWORD_KEYS[$key]))
		{
			return false;
		}

		return (bool)preg_match(self::SECRET_KEY_PATTERN, $key);
	}
}
