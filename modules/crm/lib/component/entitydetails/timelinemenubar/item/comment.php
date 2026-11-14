<?php

namespace Bitrix\Crm\Component\EntityDetails\TimelineMenuBar\Item;

use Bitrix\Crm\Component\EntityDetails\TimelineMenuBar\Item;
use Bitrix\Crm\Service\Timeline\Item\Factory\HistoryItem;

class Comment extends Item
{
	public function getId(): string
	{
		return 'comment';
	}

	public function getName(): string
	{
		return \Bitrix\Main\Localization\Loc::getMessage('CRM_TIMELINE_COMMENT');
	}

	public function isAvailable(): bool
	{
		return true;
	}

	protected function prepareSettings(): array
	{
		return [
			'pinnedItemsLimit' => HistoryItem::MAX_PINNED_ITEMS_COUNT,
			'isTextEditorEnabled' => $this->context->isCommentTextEditorEnabled(),
		];
	}
}
