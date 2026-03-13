<?php

namespace Bitrix\HumanResources\Item;

use Bitrix\HumanResources\Contract\Item;
use Bitrix\HumanResources\Type\UserSettingsType;
use Bitrix\Main\Type\DateTime;

class UserSettings implements Item
{
	public function __construct(
		public int $userId,
		public UserSettingsType $settingsType,
		public ?string $settingsValue = null,
		public ?int $id = null,
		public ?DateTime $createdAt = null,
		public ?DateTime $updatedAt = null,
	) {}
}
