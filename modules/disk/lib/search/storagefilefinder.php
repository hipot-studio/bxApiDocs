<?php

declare(strict_types=1);

namespace Bitrix\Disk\Search;

use Bitrix\Disk;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Search;

class StorageFileFinder
{
	public function __construct(
		protected int $userId,
		protected int $limit = 30,
		protected array $order = ['UPDATE_TIME' => 'DESC'],
		protected array $entityTypes = [
			Disk\ProxyType\User::class,
			Disk\ProxyType\Group::class,
			Disk\ProxyType\Common::class,
		],
		protected ?StorageFileFinderOptions $options = null,
	)
	{
	}

	/**
	 * Find object IDs based on a full-text search query.
	 *
	 * @param string $searchQuery
	 * @return array
	 */
	public function findIdsByText(string $searchQuery): array
	{
		if ($this->options !== null)
		{
			return $this->findIdsByTextWithOptions($searchQuery);
		}

		if (empty($this->entityTypes))
		{
			return [];
		}

		$filter = [
			'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
			'STORAGE.USE_INTERNAL_RIGHTS' => true,
			'=STORAGE.MODULE_ID' => Driver::INTERNAL_MODULE_ID,
			'@STORAGE.ENTITY_TYPE' => $this->entityTypes,
		];

		$fulltextContent = Disk\Search\FullTextBuilder::create()
			->addText($searchQuery)
			->getSearchValue()
		;

		if (empty($fulltextContent) || !Search\Content::canUseFulltextSearch($fulltextContent))
		{
			return [];
		}

		if (Reindex\HeadIndex::isReady())
		{
			$filter['*HEAD_INDEX.SEARCH_INDEX'] = $fulltextContent;
		}
		elseif (Reindex\BaseObjectIndex::isReady())
		{
			$filter['*SEARCH_INDEX'] = $fulltextContent;
		}
		else
		{
			return [];
		}

		$securityContext = new Disk\Security\DiskSecurityContext($this->userId);
		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
			$securityContext,
			[
				'select' => ['ID'],
				'filter' => $filter,
				'limit' => $this->limit,
				'order' => $this->order,
			],
			['ID', 'CREATED_BY']
		);

		$objectIds = [];
		foreach (ObjectTable::getList($parameters) as $row)
		{
			$objectIds[] = $row['ID'];
		}

		return $objectIds;
	}

	/**
	 * Retrieve Disk\BaseObject models based on a search query.
	 *
	 * @param string $searchQuery
	 * @return Disk\BaseObject[]
	 */
	public function findModelsByText(string $searchQuery): array
	{
		$objectIds = $this->findIdsByText($searchQuery);

		if (empty($objectIds))
		{
			return [];
		}

		if ($this->options !== null)
		{
			return $this->loadModelsInIdOrder($objectIds);
		}

		$parameters = [
			'filter' => ['@ID' => $objectIds],
			'order' => $this->order,
		];
		$objects = [];

		foreach (Disk\BaseObject::getModelList($parameters) as $object)
		{
			$objects[] = $object;
		}

		return $objects;
	}

	private function findIdsByTextWithOptions(string $searchQuery): array
	{
		$objectTypes = $this->options->getObjectTypes();
		$proxyTypes = $this->options->getProxyTypes();
		if ($objectTypes === [] || $proxyTypes === [])
		{
			return [];
		}

		$fulltextContent = Disk\Search\FullTextBuilder::create()
			->addText($searchQuery)
			->getSearchValue()
		;

		if (empty($fulltextContent) || !Search\Content::canUseFulltextSearch($fulltextContent))
		{
			return [];
		}

		$filter = [
			'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
			'STORAGE.USE_INTERNAL_RIGHTS' => true,
		];

		if (Reindex\HeadIndex::isReady())
		{
			$filter['*HEAD_INDEX.SEARCH_INDEX'] = $fulltextContent;
		}
		elseif (Reindex\BaseObjectIndex::isReady())
		{
			$filter['*SEARCH_INDEX'] = $fulltextContent;
		}
		else
		{
			return [];
		}

		if ($proxyTypes !== null)
		{
			$filter['@STORAGE.ENTITY_TYPE'] = $proxyTypes;
		}

		if ($this->options->getStorageId() !== null)
		{
			$filter['=STORAGE_ID'] = $this->options->getStorageId();
		}

		if ($this->options->getFolderId() !== null)
		{
			$filter['=PATH_CHILD.PARENT_ID'] = $this->options->getFolderId();
			$filter['!=PATH_CHILD.OBJECT_ID'] = $this->options->getFolderId();
		}

		$filter[] = $this->buildObjectTypeFilter($objectTypes);

		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
			new Disk\Security\DiskSecurityContext($this->userId),
			[
				'select' => ['ID'],
				'filter' => $filter,
				'limit' => $this->options->getLimit(),
				'offset' => $this->options->getOffset(),
				'order' => [
					'UPDATE_TIME' => 'DESC',
					'ID' => 'DESC',
				],
			],
			['ID', 'CREATED_BY'],
		);

		$objectIds = [];
		foreach (ObjectTable::getList($parameters) as $row)
		{
			$objectIds[] = (int)$row['ID'];
		}

		return $objectIds;
	}

	private function buildObjectTypeFilter(array $objectTypes): array
	{
		$excludedProxyType = $this->options->getFolderExcludedProxyType();
		if ($excludedProxyType === null || !in_array(ObjectTable::TYPE_FOLDER, $objectTypes, true))
		{
			return ['@TYPE' => $objectTypes];
		}

		$typeFilter = ['LOGIC' => 'OR'];
		foreach ($objectTypes as $objectType)
		{
			$condition = ['=TYPE' => $objectType];
			if ($objectType === ObjectTable::TYPE_FOLDER)
			{
				$condition['!=STORAGE.ENTITY_TYPE'] = $excludedProxyType;
			}
			$typeFilter[] = $condition;
		}

		return $typeFilter;
	}

	private function loadModelsInIdOrder(array $objectIds): array
	{
		$modelsById = [];
		foreach (Disk\BaseObject::getModelList([
			'filter' => ['@ID' => $objectIds],
			'with' => ['STORAGE'],
		]) as $object)
		{
			$modelsById[$object->getId()] = $object;
		}

		$objects = [];
		foreach ($objectIds as $objectId)
		{
			if (isset($modelsById[$objectId]))
			{
				$objects[] = $modelsById[$objectId];
			}
		}

		return $objects;
	}
}
