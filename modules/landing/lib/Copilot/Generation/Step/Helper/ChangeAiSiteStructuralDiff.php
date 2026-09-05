<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step\Helper;

/**
 * Detects layout regression between two HTML versions of the same block:
 * the container class set of the newer version got smaller than the previous one.
 */
final class ChangeAiSiteStructuralDiff
{
	private const CLASS_ATTRIBUTE_PATTERN = '/\bclass\s*=\s*(["\'])(.*?)\1/isu';
	private const CONTAINER_TOKEN_PATTERN = '/^(?:(?:sm|md|lg|xl|2xl):)?(?:grid|flex|grid-cols-\d+)$/u';

	public static function hasStructuralRegression(string $before, string $after): bool
	{
		return self::compare($before, $after)['regression'];
	}

	public static function countContainers(string $html): int
	{
		return count(self::collectContainerTokens($html) ?? []);
	}

	/**
	 * Counts both sides in a single pass. A side that cannot be scanned is never reported
	 * as a regression: the gate must not act on numbers it failed to collect.
	 *
	 * @return array{before: int, after: int, regression: bool}
	 */
	public static function compare(string $before, string $after): array
	{
		$beforeTokens = self::collectContainerTokens($before);
		$afterTokens = self::collectContainerTokens($after);
		if ($beforeTokens === null || $afterTokens === null)
		{
			return [
				'before' => count($beforeTokens ?? []),
				'after' => count($afterTokens ?? []),
				'regression' => false,
			];
		}

		return [
			'before' => count($beforeTokens),
			'after' => count($afterTokens),
			'regression' => count($afterTokens) < count($beforeTokens),
		];
	}

	/**
	 * @return list<string>|null null when the scan failed: broken UTF-8 or an exceeded PCRE limit
	 */
	private static function collectContainerTokens(string $html): ?array
	{
		$matchCount = preg_match_all(self::CLASS_ATTRIBUTE_PATTERN, $html, $matches);
		if ($matchCount === false)
		{
			return null;
		}

		if ($matchCount === 0)
		{
			return [];
		}

		$tokens = [];
		foreach ($matches[2] as $classValue)
		{
			foreach (self::splitClassTokens((string)$classValue) as $token)
			{
				if (preg_match(self::CONTAINER_TOKEN_PATTERN, $token) === 1)
				{
					$tokens[$token] = true;
				}
			}
		}

		return array_keys($tokens);
	}

	/**
	 * @return list<string>
	 */
	private static function splitClassTokens(string $classValue): array
	{
		return array_values(array_filter(preg_split('/\s+/u', trim($classValue)) ?: []));
	}
}
