<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Folder;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\FileLink;

/**
 * Data the rows of a page of the folder list read, loaded in a batch before the rows are built: the
 * originals of links by one query and the stored files of the page by one query at most.
 *
 * The originals are loaded without a filter of rights and from the trashcan too, as getRealObject() loads
 * them; the rights are checked by the list as before. A link whose original is gone is left alone.
 *
 * @internal
 */
final class FolderListRowPreloader
{
	public function __construct(
		private readonly StoredFileRecordLoader $loader = new StoredFileRecordLoader(),
	)
	{
	}

	/**
	 * @param BaseObject[] $objects Models of the page.
	 * @param array<int, array> $recordsByFileId Records of b_file already read, by the id of the stored file.
	 * @param int[] $versionedFileIds Ids of the stored files the rows show to have a version: they are not read.
	 * @return void
	 */
	public function preload(array $objects, array $recordsByFileId = [], array $versionedFileIds = []): void
	{
		$originals = $this->primeOriginals($objects);
		$this->primeStoredFiles($objects, $originals, $recordsByFileId, $versionedFileIds);
	}

	/**
	 * @param BaseObject[] $objects
	 * @return array<int, BaseObject> Originals of the links by their id.
	 */
	private function primeOriginals(array $objects): array
	{
		$links = [];
		foreach ($objects as $object)
		{
			if ($object instanceof BaseObject && $object->isLink() && (int)$object->getRealObjectId() > 0)
			{
				$links[] = $object;
			}
		}

		if (empty($links))
		{
			return [];
		}

		$originalIds = array_values(array_unique(array_map(
			static fn (BaseObject $link): int => (int)$link->getRealObjectId(),
			$links,
		)));

		$originals = [];
		foreach (BaseObject::loadBatchById($originalIds) as $original)
		{
			$originals[(int)$original->getId()] = $original;
		}

		foreach ($links as $link)
		{
			$original = $originals[(int)$link->getRealObjectId()] ?? null;
			if ($original !== null)
			{
				$link->primeRealObject($original);
			}
		}

		return $originals;
	}

	/**
	 * @param BaseObject[] $objects
	 * @param array<int, BaseObject> $originals
	 * @param array<int, array> $recordsByFileId
	 * @param int[] $versionedFileIds
	 */
	private function primeStoredFiles(
		array $objects,
		array $originals,
		array $recordsByFileId,
		array $versionedFileIds,
	): void
	{
		$sourcesByFileId = [];
		foreach ($objects as $object)
		{
			if (!$object instanceof File)
			{
				continue;
			}

			// An original that is gone is not read here: the link keeps the path it took before the batch.
			$source = $object instanceof FileLink ? ($originals[(int)$object->getRealObjectId()] ?? null) : $object;
			if (!$source instanceof File)
			{
				continue;
			}

			$fileId = (int)$source->getFileId();
			if ($fileId > 0)
			{
				// Two links of one original share the model of the original.
				$sourcesByFileId[$fileId][spl_object_id($source)] = $source;
			}
		}

		if (empty($sourcesByFileId))
		{
			return;
		}

		$records = array_intersect_key($recordsByFileId, $sourcesByFileId);
		// CFile answers for a file with a version, load() would leave it out anyway.
		$missingIds = array_keys(array_diff_key(
			$sourcesByFileId,
			$records,
			array_flip(array_map('intval', $versionedFileIds)),
		));
		$records += $this->loader->load($missingIds);

		foreach ($records as $fileId => $record)
		{
			foreach ($sourcesByFileId[$fileId] ?? [] as $source)
			{
				$source->primeFileData($record);
			}
		}
	}
}
