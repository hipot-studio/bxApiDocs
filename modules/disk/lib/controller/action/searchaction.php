<?php

namespace Bitrix\Disk\Controller\Action;

use Bitrix\Disk;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Access\DownloadAccess;
use Bitrix\Disk\Search\StorageFileFinder;
use Bitrix\Main\Search;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Web\Uri;

class SearchAction extends Search\SearchAction
{
	public function provideData($searchQuery, ?array $options = null, ?PageNavigation $pageNavigation = null)
	{
		if (!$this->getCurrentUser())
		{
			return [];
		}

		$objectIds = $this->findObjectIdsByText($searchQuery);

		$result = [];
		if (!$objectIds)
		{
			return $result;
		}

		$objects = $this->getObjectsByIds($objectIds);
		$securityContextsByFileId = DownloadAccess::preloadForFilesBySecurityContext(
			$objects,
			(int)$this->getCurrentUser()->getId(),
		);

		foreach ($objects as $object)
		{
			$type = $object instanceof Disk\File ? 'file' : 'folder';
			$resultItem = new Search\ResultItem($object->getName(), $this->buildUriForSearchPresentation($object), $object->getId());
			$resultItem->setType($type);

			if ($object instanceof Disk\File)
			{
				$securityContext = $securityContextsByFileId[spl_object_id($object)] ?? null;

				if ($securityContext && $object->canDownload($securityContext))
				{
					$resultItem->addLink('download', $this->buildDownloadUriForSearchResult($object));
				}
			}

			$result[] = $resultItem;
		}

		return $result;
	}

	protected function findObjectIdsByText(string $searchQuery): array
	{
		$fileFinder = new StorageFileFinder($this->getCurrentUser()->getId());

		return $fileFinder->findIdsByText($searchQuery);
	}

	protected function getObjectsByIds(array $objectIds): array
	{
		return Disk\BaseObject::getModelList([
			'filter' => [
				'@ID' => $objectIds,
			],
		]);
	}

	protected function buildDownloadUriForSearchResult(Disk\File $object)
	{
		return Driver::getInstance()->getUrlManager()->getUrlForDownloadFile($object);
	}

	protected function buildUriForSearchPresentation(Disk\BaseObject $object)
	{
		$urlManager = Driver::getInstance()->getUrlManager();

		if ($object instanceof Disk\File)
		{
			return new Uri(
				$urlManager->getUrlFocusController(
					'openFileDetail',
					[
						'fileId' => $object->getId(),
						'inSidePanel' => 1,
					],
				),
			);
		}

		return new Uri(
			$urlManager->getUrlFocusController(
				'openFolderList',
				[
					'folderId' => $object->getId(),
				],
			),
		);
	}
}
