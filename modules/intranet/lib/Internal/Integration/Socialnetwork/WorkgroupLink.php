<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Socialnetwork;

use Bitrix\Main\Web\Uri;

final class WorkgroupLink
{
	private const FAVORITE_LINK_SUFFIXES = [
		'tasks/',
		'disk/path/',
		'calendar/',
		'general/',
		'',
	];

	public static function parse(string $link): ?array
	{
		$path = (new Uri($link))->getPath();
		if (str_contains($path, '//') || preg_match(self::getLinkPattern(), $path, $matches) !== 1)
		{
			return null;
		}

		$groupId = (int)$matches['groupId'];
		if ($groupId <= 0)
		{
			return null;
		}

		$canonicalLink = $matches['suffix'] === ''
			? $matches['sitePrefix'] . 'workgroups/group/' . $groupId . '/general/'
			: $path;

		return [
			'groupId' => $groupId,
			'isFeed' => $matches['suffix'] === 'general/' || $matches['suffix'] === '',
			'canonicalLink' => $canonicalLink,
		];
	}

	private static function getLinkPattern(): string
	{
		static $pattern = null;

		if ($pattern === null)
		{
			$suffixes = array_map(
				static fn(string $suffix): string => preg_quote($suffix, '~'),
				self::FAVORITE_LINK_SUFFIXES,
			);
			$pattern = '~^(?<sitePrefix>(?:.*/)?)workgroups/group/(?<groupId>[0-9]+)/'
				. '(?<suffix>' . implode('|', $suffixes) . ')$~';
		}

		return $pattern;
	}

	public static function extractGroupId(string $link): ?int
	{
		return self::parse($link)['groupId'] ?? null;
	}

	public static function pointsToGroup(string $link, int $groupId): bool
	{
		if ($groupId <= 0)
		{
			return false;
		}

		return self::extractGroupId($link) === $groupId;
	}

	public static function getFavoriteItemIds(int $groupId): array
	{
		if ($groupId <= 0)
		{
			return [];
		}

		return array_map(
			static fn(string $suffix): string => (string)crc32(
				'/workgroups/group/' . $groupId . '/' . $suffix,
			),
			self::FAVORITE_LINK_SUFFIXES,
		);
	}
}
