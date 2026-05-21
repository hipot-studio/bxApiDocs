<?php

namespace Bitrix\Catalog\UserField;

use Bitrix\Catalog\Access\AccessController;
use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Catalog\Controller\Controller;
use Bitrix\Catalog\Document\StoreDocumentTableManager;
use Bitrix\Catalog\StoreTable;
use Bitrix\Main\Loader;

class UserFieldAccess extends \Bitrix\Main\UserField\UserFieldAccess
{
	protected function getAvailableEntityIds(): array
	{
		$iblockEntities = array_map(fn($item): string => 'IBLOCK_' . $item . '_SECTION', static::getIBlockList());

		$accessController = AccessController::getInstance($this->userId);

		$storeDocsEntities = [];
		if ($accessController->check(ActionDictionary::ACTION_INVENTORY_MANAGEMENT_ACCESS))
		{
			foreach (StoreDocumentTableManager::getUfEntityIds() as $type => $entityId)
			{
				if ($accessController->checkByValue(ActionDictionary::ACTION_STORE_DOCUMENT_MODIFY, $type))
				{
					$storeDocsEntities[] = $entityId;
				}
			}
		}

		$result = [
			...$iblockEntities,
			...$storeDocsEntities,
		];

		if ($accessController->check(ActionDictionary::ACTION_STORE_MODIFY))
		{
			$result[] = StoreTable::getUfId();
		}

		return $result;
	}

	protected static function getIBlockList(): array
	{
		Loader::includeModule('catalog');
		Loader::includeModule('iblock');

		$list = [];
		$filter = [];

		$r = \CCatalog::GetList();
		while ($l = $r->fetch())
		{
			$filter['ID'] ??= [];
			$filter['ID'][] = $l['IBLOCK_ID'];
		}

		$filter['ACTIVE'] = 'Y';
		$filter['OPERATION'] = Controller::IBLOCK_EDIT;

		$iterator = \CIBlock::GetList(['ID' => 'ASC'], $filter);
		while ($iblock = $iterator->Fetch())
		{
			$list[] = (int)$iblock['ID'];
		}

		return $list;
	}

	public function getRestrictedTypes(): array
	{
		return array_merge(
			parent::getRestrictedTypes(),
			[
				'video',
				'vote',
				'url_preview',
				'string_formatted',
				'disk_file',
				'disk_version',
			]
		);
	}
}
