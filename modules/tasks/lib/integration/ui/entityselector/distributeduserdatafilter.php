<?php

namespace Bitrix\Tasks\Integration\UI\EntitySelector;

use Bitrix\Main\Localization\Loc;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Tab;

class DistributedUserDataFilter extends UserDataFilter
{
	public function apply(array $items, Dialog $dialog): void
	{
		parent::apply($items, $dialog);

		$emailUsersTab = new Tab([
			'id' => 'email-users',
			'title' => Loc::getMessage('TASKS_UI_ENTITY_SELECTOR_DISTRIBUTED_USER_FILTER_EMAIL_USERS'),
			'icon' => [
				'default' => 'o-group',
				'selected' => 's-group',
			],
		]);
		$dialog->addTab($emailUsersTab);

		foreach ($items as $item)
		{
			if ($item->getEntityType() === 'email')
			{
				$item->addTab('email-users');
			}
		}
	}
}
