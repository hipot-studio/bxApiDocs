<?php
declare(strict_types=1);

namespace Bitrix\Landing\Vibe;

/**
 * Normalizes external embedId values to the allowed contract.
 *
 * Allowed characters: `a-z`, `A-Z`, `0-9`, `_`.
 * Any other symbol is removed.
 *
 * The normalizer is error-less: it does not throw; invalid input is
 * treated as part of the contract.
 */
final class EmbedIdNormalizer
{
	/**
	 * @param string $embedId External embedId value.
	 * @return string Normalized embedId compliant with (`a-z`, `A-Z`, `0-9`, `_`).
	 */
	public static function normalize(string $embedId): string
	{
		$embedId = trim($embedId);

		// Keep only [A-Za-z0-9_]
		return (string) preg_replace('/[^A-Za-z0-9_]/', '', $embedId);
	}
}

