<?php

declare(strict_types=1);

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Public\Provider\LegalRegistry\CatalogProvider;
use Bitrix\Crm\WebForm\Manager;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CrmLegalDocumentCatalogComponent extends CBitrixComponent
{
	public function executeComponent(): void
	{
		if (!Loader::includeModule('crm')) { return; }
		$userId = $this->currentUserId();
		if (!(new CatalogProvider())->getSchemaAccess($userId)->isSuccess()) { return; }
		$base = Manager::getUrl();
		if (!is_string($base) || !str_starts_with($base, '/') || str_starts_with($base, '//')) { return; }
		$this->arResult = [
			'CAN_READ' => true,
			'CAN_EDIT' => Container::getInstance()->getUserPermissions($userId)->webForm()->canEdit(),
			'CARD_URL' => (string)(new Uri($base))->addParams(['legal_document' => 'card']),
		];
		$this->IncludeComponentTemplate();
	}

	protected function currentUserId(): int
	{
		$user = $GLOBALS['USER'] ?? null;

		return $user instanceof CUser ? (int)$user->GetID() : 0;
	}
}
