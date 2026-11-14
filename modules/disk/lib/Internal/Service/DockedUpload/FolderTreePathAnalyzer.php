<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

/**
 * Pure helpers over a set of relative directory paths (API-04 createFolderTree),
 * shared by the controller (input validation, Q-6) and CreateFolderTreeCommand
 * (sync-vs-stepper selection). The cost of a folder-tree request is the number of
 * distinct nodes it materialises, not the number of paths - a single deep path can
 * create many folders - so both surfaces size the request by unique node count.
 */
final class FolderTreePathAnalyzer
{
	/**
	 * Number of distinct tree nodes across every path: the union of all path
	 * prefixes. "a/b/c" contributes a, a/b, a/b/c; paths sharing a prefix count it
	 * once.
	 *
	 * @param string[] $relativePaths
	 */
	public static function countUniqueNodes(array $relativePaths): int
	{
		$nodes = [];
		foreach ($relativePaths as $path)
		{
			$prefix = '';
			foreach (self::segments((string)$path) as $segment)
			{
				$prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
				$nodes[$prefix] = true;
			}
		}

		return count($nodes);
	}

	/**
	 * Non-empty path segments (a '/'-joined path split, empty parts dropped).
	 *
	 * @return string[]
	 */
	public static function segments(string $path): array
	{
		return array_values(array_filter(
			explode('/', trim($path, '/')),
			static fn(string $segment): bool => $segment !== '',
		));
	}
}
