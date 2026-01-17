<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription\Mapper;

use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Entity\CheckList;

class CheckListMapper
{
	public function convertFromTranscribedMessage(array $checkListData): ?CheckList
	{
		if (empty($checkListData) || !Loader::includeModule('tasks'))
		{
			return null;
		}

		$checkLists = new CheckList();

		$nodeId = 1;
		$checkListSortIndex = 1;

		foreach ($checkListData as $checkList)
		{
			$title = $checkList['title'] ?? '';
			if (empty($title))
			{
				continue;
			}

			$checkListNodeId = $nodeId++;

			$checkLists->add(new CheckList\CheckListItem(
				nodeId: (string)$checkListNodeId,
				title: $title,
				sortIndex: $checkListSortIndex++,
			));

			$items = $checkList['items'] ?? [];
			foreach ($items as $itemSortIndex => $itemTitle)
			{
				if (empty($itemTitle))
				{
					continue;
				}

				$checkLists->add(new CheckList\CheckListItem(
					nodeId: (string)$nodeId++,
					title: $itemTitle,
					parentNodeId: (string)$checkListNodeId,
					sortIndex: (int)$itemSortIndex,
				));
			}
		}

		return $checkLists;
	}
}
