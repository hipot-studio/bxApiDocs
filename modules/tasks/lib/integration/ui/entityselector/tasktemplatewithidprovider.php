<?php

namespace Bitrix\Tasks\Integration\UI\EntitySelector;

use Bitrix\Tasks\V2\Internal\Entity\Template\TypeDictionary;
use Bitrix\UI\EntitySelector\Item;

class TaskTemplateWithIdProvider extends TaskTemplateProvider
{
	protected const ENTITY_ID = 'task-template-with-id';

	protected function getFilterByOptions(array $options): array
	{
		return [
			...parent::getFilterByOptions($options),
			'!TPARAM_TYPE' => TypeDictionary::NEW_USERS,
		];
	}

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
