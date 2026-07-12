<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Socialnetwork\WorkgroupSiteTable;
use Bitrix\Socialnetwork\WorkgroupTagTable;
use Bitrix\Socialnetwork\V2\Internal\Integration\Extranet\Service\ExtranetUserService;

class WorkgroupFilterRepository implements WorkgroupFilterRepositoryInterface
{
	public function __construct(
		private readonly ExtranetUserService $extranetUserService,
	)
	{
	}

	public function getGroupIdsByTag(string $tag): array
	{
		$tag = trim($tag);
		if ($tag === '')
		{
			return [];
		}

		$rows = WorkgroupTagTable::query()
			->setSelect(['GROUP_ID'])
			->whereLike('NAME', $tag . '%')
			->exec()
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$groupId = (int)($row['GROUP_ID'] ?? 0);
			if ($groupId > 0)
			{
				$result[$groupId] = true;
			}
		}

		return array_keys($result);
	}

	public function getExtranetGroupIds(): array
	{
		$extranetSiteId = $this->extranetUserService->getExtranetSiteId();
		if ($extranetSiteId === '')
		{
			return [];
		}

		$rows = WorkgroupSiteTable::getList([
			'filter' => ['=SITE_ID' => $extranetSiteId],
			'select' => ['GROUP_ID'],
		])->fetchAll();

		$result = [];
		foreach ($rows as $row)
		{
			$groupId = (int)($row['GROUP_ID'] ?? 0);
			if ($groupId > 0)
			{
				$result[$groupId] = true;
			}
		}

		return array_keys($result);
	}
}
