<?php

namespace Bitrix\Sign\Repository\Document;

use Bitrix\Main\DB\Order;
use Bitrix\Main\Result;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Sign\Internal\Document\DocumentFolder;
use Bitrix\Sign\Internal\Document\DocumentFolderCollection;
use Bitrix\Sign\Internal\Document\DocumentFolderTable;
use Bitrix\Sign\Item;
use Bitrix\Sign\Type;

/**
 * Read/write access to company safe folders (b_sign_document_folder). Reads feed the access
 * layer (folder ownership resolution); writes back the folder CRUD services (Phase 3).
 * Company safe folders do not use the VISIBILITY/STATUS columns (owner-scoped access model),
 * so they are persisted with neutral zero values.
 */
class SafeFolderRepository
{
	private const NEUTRAL_VISIBILITY = 0;
	private const NEUTRAL_STATUS = 0;

	public function add(Item\Document\SafeFolder $item): Result
	{
		$model = DocumentFolderTable::createObject(false);
		$model
			->setTitle($item->title)
			->setCreatedById($item->createdById)
			->setDateCreate($item->dateCreate)
			->setVisibility(self::NEUTRAL_VISIBILITY)
			->setStatus(self::NEUTRAL_STATUS)
		;
		if ($item->modifiedById !== null)
		{
			$model->setModifiedById($item->modifiedById);
		}
		if ($item->dateModify !== null)
		{
			$model->setDateModify($item->dateModify);
		}

		$saveResult = $model->save();
		if (!$saveResult->isSuccess())
		{
			return (new Result())->addErrors($saveResult->getErrors());
		}

		$item->id = $saveResult->getId();
		$item->initOriginal();

		return (new Result())->setData(['folder' => $item]);
	}

	public function update(Item\Document\SafeFolder $item): Result
	{
		$id = $item->getId();
		if ($id <= 0)
		{
			return (new Result())->addError(new \Bitrix\Main\Error('Folder id is not set'));
		}

		$model = DocumentFolderTable::getByPrimary($id)->fetchObject();
		if ($model === null)
		{
			return (new Result())->addError(new \Bitrix\Main\Error('Folder not found'));
		}

		$model->setTitle($item->title);
		if ($item->modifiedById !== null)
		{
			$model->setModifiedById($item->modifiedById);
		}
		if ($item->dateModify !== null)
		{
			$model->setDateModify($item->dateModify);
		}

		$saveResult = $model->save();
		if (!$saveResult->isSuccess())
		{
			return (new Result())->addErrors($saveResult->getErrors());
		}

		$item->initOriginal();

		return (new Result())->setData(['folder' => $item]);
	}

	public function deleteById(int $id): Result
	{
		if ($id <= 0)
		{
			return (new Result())->addError(new \Bitrix\Main\Error('Folder id is not set'));
		}

		$deleteResult = DocumentFolderTable::delete($id);
		if (!$deleteResult->isSuccess())
		{
			return (new Result())->addErrors($deleteResult->getErrors());
		}

		return new Result();
	}

	public function getById(int $id): ?Item\Document\SafeFolder
	{
		if ($id <= 0)
		{
			return null;
		}

		$model = DocumentFolderTable::getByPrimary($id)->fetchObject();
		if ($model === null)
		{
			return null;
		}

		return $this->extractItemFromModel($model);
	}

	/**
	 * @param list<int> $ids
	 */
	public function getByIds(array $ids): Item\Document\SafeFolderCollection
	{
		$ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
		if (empty($ids))
		{
			return new Item\Document\SafeFolderCollection();
		}

		$models = DocumentFolderTable::query()
			->setSelect(['*'])
			->whereIn('ID', $ids)
			->fetchCollection()
		;

		return $this->extractItemCollectionFromModelCollection($models);
	}

	/**
	 * All company safe folders. Feeds the root-level folder rows (NORMATIVE ALG-02) when the
	 * user's folder read scope is ALL (or the user is an admin).
	 */
	public function getAll(): Item\Document\SafeFolderCollection
	{
		$models = DocumentFolderTable::query()
			->setSelect(['*'])
			->fetchCollection()
		;

		return $this->extractItemCollectionFromModelCollection($models);
	}

	/**
	 * Batch-resolves folder titles by id in a single query (no N+1). Feeds the MySafe REST
	 * `folderName` column for the current page of records.
	 *
	 * @param list<int> $ids
	 * @return array<int, string> folderId => title
	 */
	public function getTitlesByIds(array $ids): array
	{
		$ids = $this->normalizeIds($ids);
		if (empty($ids))
		{
			return [];
		}

		$rows = DocumentFolderTable::query()
			->setSelect(['ID', 'TITLE'])
			->whereIn('ID', $ids)
			->fetchAll()
		;

		$titles = [];
		foreach ($rows as $row)
		{
			$titles[(int)$row['ID']] = (string)$row['TITLE'];
		}

		return $titles;
	}

	/**
	 * Folders owned by the given users (owner-scope resolution for folder read).
	 *
	 * @param list<int> $ownerIds
	 */
	public function getByOwnerIds(array $ownerIds): Item\Document\SafeFolderCollection
	{
		$ownerIds = $this->normalizeIds($ownerIds);
		if (empty($ownerIds))
		{
			return new Item\Document\SafeFolderCollection();
		}

		$models = DocumentFolderTable::query()
			->setSelect(['*'])
			->whereIn('CREATED_BY_ID', $ownerIds)
			->fetchCollection()
		;

		return $this->extractItemCollectionFromModelCollection($models);
	}

	/**
	 * @param list<int>|null $ownerIds null means unrestricted owner scope
	 * @param list<int>|null $folderIds null means no folder-id restriction
	 */
	public function countByScope(?array $ownerIds, ?array $folderIds = null): int
	{
		return (int)$this->buildScopeQuery($ownerIds, $folderIds)->queryCountTotal();
	}

	/**
	 * @param list<int>|null $ownerIds null means unrestricted owner scope
	 * @param list<int>|null $folderIds null means no folder-id restriction
	 * @param Order|null $titleOrder null defaults to newest-first (ID descending)
	 */
	public function listByScope(
		?array $ownerIds,
		int $limit,
		int $offset,
		?array $folderIds = null,
		?Order $titleOrder = null,
	): Item\Document\SafeFolderCollection
	{
		if ($limit <= 0)
		{
			return new Item\Document\SafeFolderCollection();
		}

		$order = ['ID' => 'DESC'];
		if ($titleOrder !== null)
		{
			$order = ['TITLE' => $titleOrder->value, 'ID' => 'ASC'];
		}

		$models = $this->buildScopeQuery($ownerIds, $folderIds)
			->setSelect(['*'])
			->setOrder($order)
			->setLimit($limit)
			->setOffset(max(0, $offset))
			->fetchCollection()
		;

		return $this->extractItemCollectionFromModelCollection($models);
	}

	/**
	 * @param list<int>|null $ownerIds
	 * @param list<int>|null $folderIds
	 */
	private function buildScopeQuery(?array $ownerIds, ?array $folderIds): Query
	{
		$query = DocumentFolderTable::query();
		if ($ownerIds !== null)
		{
			$ownerIds = $this->normalizeIds($ownerIds);
			if ($ownerIds === [])
			{
				return $query->where('ID', -1);
			}

			$query->whereIn('CREATED_BY_ID', $ownerIds);
		}

		if ($folderIds !== null)
		{
			$folderIds = $this->normalizeIds($folderIds);
			if ($folderIds === [])
			{
				return $query->where('ID', -1);
			}

			$query->whereIn('ID', $folderIds);
		}

		return $query;
	}

	/**
	 * @param list<int> $ids
	 * @return list<int>
	 */
	private function normalizeIds(array $ids): array
	{
		return array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
	}

	private function extractItemFromModel(DocumentFolder $model): Item\Document\SafeFolder
	{
		return new Item\Document\SafeFolder(
			title: (string)$model->getTitle(),
			createdById: (int)$model->getCreatedById(),
			id: $model->getId(),
			modifiedById: $model->getModifiedById(),
			dateModify: Type\DateTime::createFromMainDateTimeOrNull($model->getDateModify()),
			dateCreate: Type\DateTime::createFromMainDateTime($model->getDateCreate()),
		);
	}

	private function extractItemCollectionFromModelCollection(
		DocumentFolderCollection $models,
	): Item\Document\SafeFolderCollection
	{
		$items = new Item\Document\SafeFolderCollection();
		foreach ($models as $model)
		{
			$items->add($this->extractItemFromModel($model));
		}

		return $items;
	}
}
