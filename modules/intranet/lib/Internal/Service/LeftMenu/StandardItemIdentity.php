<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\LeftMenu;

use Bitrix\Intranet\Internal\Integration\Socialnetwork\Dto\WorkgroupSectionState;
use Bitrix\Intranet\Internal\Integration\Socialnetwork\WorkgroupLink;

final class StandardItemIdentity
{
	public function resolve(string $link, int|string|null $providedId, ?WorkgroupSectionState $state): array
	{
		$canonicalLink = $state?->isAccessibleProjectSection() ? ($state->canonicalLink ?: $link) : $link;

		return [
			'link' => $canonicalLink,
			'id' => $providedId ?? crc32($canonicalLink),
		];
	}

	public function isSame(string $firstLink, string $secondLink, ?WorkgroupSectionState $state = null): bool
	{
		if ($firstLink === $secondLink)
		{
			return true;
		}
		if (!$state?->isAccessibleProjectSection())
		{
			return false;
		}

		$firstIdentity = WorkgroupLink::parse($firstLink);
		$secondIdentity = WorkgroupLink::parse($secondLink);
		if ($firstIdentity === null || $secondIdentity === null)
		{
			return false;
		}

		return $firstIdentity['canonicalLink'] === $secondIdentity['canonicalLink'];
	}

	public function findStoredItemByLink(array $items, string $link, ?WorkgroupSectionState $state = null): ?array
	{
		$canonicalMatch = null;
		foreach ($items as $key => $item)
		{
			$itemId = is_array($item) ? ($item['ID'] ?? null) : null;
			$itemLink = is_array($item) ? ($item['LINK'] ?? null) : null;
			if (
				(is_int($itemId) || is_string($itemId))
				&& is_string($itemLink)
				&& $this->isSame($itemLink, $link, $state)
			)
			{
				$match = [
					'key' => $key,
					'id' => $itemId,
					'link' => $itemLink,
				];
				if ($itemLink === $link)
				{
					return $match;
				}
				$canonicalMatch ??= $match;
			}
		}

		return $canonicalMatch;
	}
}
