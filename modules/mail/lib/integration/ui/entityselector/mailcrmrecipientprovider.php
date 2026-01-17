<?php

namespace Bitrix\Mail\Integration\UI\EntitySelector;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Tab;

class MailCrmRecipientProvider extends BaseProvider
{
	public const PROVIDER_ENTITY_ID = 'mail_crm_recipient';
	public const ITEMS_LIMIT = 6;

	public function __construct(array $options = [])
	{
		parent::__construct();
	}

	private static function addTemplatesTab($dialog): void
	{
		$dialog->addTab(new Tab([
			'id' => self::PROVIDER_ENTITY_ID,
			'title' => Loc::getMessage("MAIL_CRM_RECIPIENT_PROVIDER_TAB_TITLE"),
			'header' => Loc::getMessage("MAIL_CRM_RECIPIENT_PROVIDER_TAB_HEADER"),
			'icon' => [
				'default' => 'o-crm',
				'selected' => 's-crm',
			],
		]));
	}

	public function isAvailable(): bool
	{
		return Loader::includeModule('crm');
	}

	public function getItems(array $ids): array
	{
		return [];
	}

	public function fillDialog(Dialog $dialog): void
	{
		self::addTemplatesTab($dialog);
	}
}
