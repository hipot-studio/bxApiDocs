<?php

namespace Bitrix\Mail\Integration\UI\EntitySelector;

use Bitrix\Main\Localization\Loc;
use Bitrix\UI\EntitySelector\BaseFilter;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\Tab;
use Bitrix\Ui\Public\Enum\IconSet\Outline;
use Bitrix\Ui\Public\Enum\IconSet\Solid;

class MailUserRecipientAppearanceFilter extends BaseFilter
{
	public const MAIL_RECIPIENT_USER_TAB = 'mail-recipient-user';

	public function __construct()
	{
		parent::__construct();
	}

	public function isAvailable(): bool
	{
		return true;
	}

	protected static function addUserTab(Dialog $dialog): void
	{
		$dialog->addTab(new Tab([
			'id' => self::MAIL_RECIPIENT_USER_TAB,
			'title' => Loc::getMessage("MAIL_RECIPIENT_USER_TAB_TITLE"),
			'icon' => [
				'default' => Outline::GROUP->value,
				'selected' => Solid::GROUP->value,
			]
		]));
	}

	public function apply(array $items, Dialog $dialog): void
	{
		$usersCount = 0;

		foreach ($items as $item)
		{
			if (!($item instanceof Item))
			{
				continue;
			}

			$email = '';

			switch ($item->getEntityId())
			{
				case 'user':
				{
					$usersCount++;

					$fields = $item->getCustomData()->getValues();

					$item->getCustomData()->set('entityType', self::MAIL_RECIPIENT_USER_TAB);

					$item->addTab(self::MAIL_RECIPIENT_USER_TAB);

					if (isset($fields['email']))
					{
						$email = (string)$fields['email'];
					}

					break;
				}
			}

			$item->setSubtitle($email);
		}

		if ($usersCount > 0 && is_null($dialog->getTab(self::MAIL_RECIPIENT_USER_TAB)))
		{
			self::addUserTab($dialog);
		}
	}
}
