<?php

declare(strict_types=1);

namespace Bitrix\Lists\Internal\Integration\Bizproc\EventHandlers\OnGetDocumentTypes;

use Bitrix\Bizproc\Public\Event\Document\OnGetDocumentTypeEvent\OnGetDocumentTypeEvent;
use Bitrix\Lists\Api\Service\ServiceFactory\ListService;
use Bitrix\Lists\Api\Service\ServiceFactory\ProcessService;
use Bitrix\Lists\Api\Service\ServiceFactory\SocNetListService;
use Bitrix\Main\EventResult;
use BizprocDocument;
use CIBlock;
use CLists;

class GetDocumentTypes
{
	public function __construct()
	{}

	public static function onGetDocumentType(OnGetDocumentTypeEvent $event): void
	{
		$parameters = new ListsDocumentTypeFilter();
		$event->loadModuleParameters('lists', $parameters);

		$event->addResult(
			new EventResult(
				EventResult::SUCCESS,
				['documentTypes' => static::getDocumentTypes(static::getTypeIds($parameters))]
			)
		);
	}

	private static function getTypeIds(ListsDocumentTypeFilter $parameters): array
	{
		if ($parameters->isOnlyProcesses())
		{
			return [ProcessService::getIBlockTypeId()];
		}

		if ($parameters->isOnlyLists())
		{
			return [ListService::getIBlockTypeId()];
		}

		if ($parameters->isOnlySocNetLists())
		{
			return [SocNetListService::getIBlockTypeId()];
		}

		$groups = [
			ProcessService::getIBlockTypeId(),
			ListService::getIBlockTypeId(),
			SocNetListService::getIBlockTypeId(),
		];

		$typeIds = CLists::GetIBlockTypes();
		while ($row = $typeIds->Fetch())
		{
			$groups[] = (string)$row['IBLOCK_TYPE_ID'];
		}

		return array_values(array_unique(array_filter($groups)));
	}

	private static function getDocumentTypes(array $typeIds): array
	{
		$iterator = CIBlock::GetList(
			['SORT' => 'ASC', 'NAME' => 'ASC'],
			[
				'ACTIVE' => 'Y',
				'TYPE' => count($typeIds) === 1 ? current($typeIds) : $typeIds,
				'CHECK_PERMISSIONS' => 'N',
			]
		);

		$documentTypes = [];
		while ($row = $iterator->Fetch())
		{
			$documentTypes[] = BizprocDocument::generateDocumentComplexType((string)$row['IBLOCK_TYPE_ID'], (int)$row['ID']);
		}

		return $documentTypes;
	}
}
