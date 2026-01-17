<?php

namespace Bitrix\Tasks\Integration\UI\EntitySelector;

use Bitrix\UI\EntitySelector\Item;

class TaskTemplateWithIdProvider extends TaskTemplateProvider
{
	protected const ENTITY_ID = 'task-template-with-id';

	protected static function makeItem(int $id, string|null $title): Item
	{
		return new Item([
			'entityId' => static::ENTITY_ID,
			'id' => $id,
			'title' => "{$title}[$id]",
			'tabs' => ['recents', static::ENTITY_ID],
		]);
	}
}
