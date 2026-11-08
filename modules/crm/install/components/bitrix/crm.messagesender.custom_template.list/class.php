<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Crm\MessageSender\CustomTemplate\CrmZone;

/**
 * Thin CRM wrapper around the generic `bitrix:messageservice.custom-template.list`
 * component. CRM-specific concerns (zone id, frontend extension, sidebar wiring)
 * stay in CRM; all grid/data/permission logic lives in messageservice.
 */
final class CrmMessagesenderCustomTemplateListComponent extends \CBitrixComponent
{
	public function executeComponent(): void
	{
		if (!CrmZone::isSupportedByMessageService())
		{
			return;
		}

		$this->arResult = [
			'ZONE' => CrmZone::ID,
			'SCENE' => (string)($this->arParams['SCENE'] ?? ''),
			'TARGET_ID' => (string)($this->arParams['TARGET_ID'] ?? ''),
		];

		$this->includeComponentTemplate();
	}
}
