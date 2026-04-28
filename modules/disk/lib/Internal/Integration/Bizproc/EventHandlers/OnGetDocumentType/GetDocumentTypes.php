<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Integration\Bizproc\EventHandlers\OnGetDocumentType;

use Bitrix\Bizproc\Public\Event\Document\OnGetDocumentTypeEvent\OnGetDocumentTypeEvent;
use Bitrix\Disk\BizProcDocument;
use Bitrix\Main\EventResult;

class GetDocumentTypes
{
	public static function onGetDocumentType(OnGetDocumentTypeEvent $event): void
	{
		$storages = \Bitrix\Disk\Storage::getList([
			'select' => ['ID'],
			'filter' => [
				'ENTITY_TYPE' => [
					\Bitrix\Disk\ProxyType\Common::className(),
					\Bitrix\Disk\ProxyType\Group::className(),
				],
			],
		]);

		$storageIds = [];
		while ($storage = $storages->fetch())
		{
			$storageIds[] = (int)$storage['ID'];
		}

		$documentTypes = [];
		foreach ($storageIds as $storageId)
		{
			$documentTypes[] = BizProcDocument::generateDocumentComplexType($storageId);
		}

		if (!$documentTypes)
		{
			return;
		}

		$event->addResult(
			new EventResult(
				EventResult::SUCCESS,
				['documentTypes' => $documentTypes]
			)
		);
	}
}
