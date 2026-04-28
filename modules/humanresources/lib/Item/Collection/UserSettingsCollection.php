<?php

namespace Bitrix\HumanResources\Item\Collection;

use Bitrix\HumanResources\Item;

/**
 * @extends BaseCollection<Item\UserSettings>
 */
class UserSettingsCollection extends BaseCollection
{
	/**
	 * @return array<int>
	 */
	public function getIntSettingsValues(): array
	{
		return $this->map(static fn($entity) => (int)$entity->settingsValue);
	}
}
