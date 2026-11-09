<?php
namespace Bitrix\Landing;

use \Bitrix\Landing\Internals\FileTable;

class File
{
	/**
	 * Entity type site.
	 */
	public const ENTITY_TYPE_SITE  = 'S';

	/**
	 * Entity type landing.
	 */
	public const ENTITY_TYPE_LANDING = 'L';

	/**
	 * Entity type block.
	 */
	public const ENTITY_TYPE_BLOCK = 'B';

	/**
	 * Entity type asset.
	 */
	public const ENTITY_TYPE_ASSET = 'A';

	/**
	 * Entity type markup upload buffer (unfinished markup package upload).
	 */
	public const ENTITY_TYPE_MARKUP_UPLOAD = 'U';

	/**
	 * Transliterates the Cyrillic characters in a file name to Latin characters and returns the new file name.
	 * @param string $fileName File name.
	 * @return string
	 */
	public static function transliterateFileName(string $fileName): string
	{
		$parts = pathinfo($fileName);
		$basename = $parts['filename'];
		$transliterateBaseName =  \CUtil::translit(
			$basename,
			'ru',
			[
				'replace_space' => '_',
				'replace_other' => '_'
			]
		);

		return $transliterateBaseName . '.' . $parts['extension'];
	}

	/**
	 * Returns sanitized file name.
	 * @param string $fileName File name.
	 * @return string
	 */
	public static function sanitizeFileName(string $fileName): string
	{
		return preg_replace(
			'/[\(\)\s]+/s',
			'_',
			$fileName
		);
	}

	/**
	 * Add new record.
	 * @param int $fileId File id.
	 * @param int $entityId Entity id.
	 * @param string $entityType Entity type.
	 * @param bool $temp This is temporary file.
	 * @return void
	 */
	protected static function add(int $fileId, int $entityId, string $entityType, bool $temp = false): void
	{
		$res = FileTable::getList(array(
			'select' => array(
				'ID'
			),
			'filter' => array(
				'FILE_ID' => $fileId,
				'ENTITY_ID' => $entityId,
				'=ENTITY_TYPE' => $entityType
			)
		));
		if (!$res->fetch())
		{
			$res = FileTable::add(array(
				'FILE_ID' => $fileId,
				'ENTITY_ID' => $entityId,
				'ENTITY_TYPE' => $entityType,
				'TEMP' => $temp ? 'Y' : 'N'
			));
			$res->isSuccess();
		}
	}

	/**
	 * Get all files id from entity.
	 * @param int $entityId Entity id.
	 * @param int $entityType Entity type.
	 * @return array
	 */
	protected static function getFiles($entityId, $entityType)
	{
		$files = [];
		$res = FileTable::getList(array(
			'select' => array(
				'FILE_ID'
			),
			'filter' => array(
				'ENTITY_ID' => $entityId,
				'=ENTITY_TYPE' => $entityType
			)
		));
		while ($row = $res->fetch())
		{
			$files[] = $row['FILE_ID'];
		}
		return $files;
	}

	/**
	 * Mark records for delete.
	 * @param int|array $fileId File id.
	 * @param int $entityId Entity id.
	 * @param int $entityType Entity type.
	 * @return void
	 */
	protected static function delete($fileId, $entityId, $entityType)
	{
		//@tmp log
		Debug::log(
			$entityId . '@' . $entityType,
			'fileId: ' . print_r($fileId, true) . '@' . print_r(\Bitrix\Main\Diag\Helper::getBackTrace(15), true),
			'LANDING_FILE_MARK_DELETE'
		);

		$filter = array(
			'ENTITY_ID' => $entityId,
			'=ENTITY_TYPE' => $entityType
		);
		if ($fileId)
		{
			$filter['FILE_ID'] = $fileId;
		}
		$res = FileTable::getList(array(
			'select' => array(
				'ID', 'FILE_ID'
			),
			'filter' => $filter
		));
		while ($row = $res->fetch())
		{
			$resUpdate = FileTable::update(
				$row['ID'],
				array(
					'FILE_ID' => -1 * abs($row['FILE_ID'])
				)
			);
			$resUpdate->isSuccess();
		}
	}

	/**
	 * Final delete all marked files.
	 * @param int $limit Records limit for one iteration.
	 * @return void
	 */
	public static function deleteFinal($limit = null)
	{
		$deletedFiles = [];

		$res = FileTable::getList([
		  	'select' => [
		 		'ID', 'FILE_ID'
		    ],
	  		'filter' => [
				'<FILE_ID' => 0
		    ],
			'limit' => $limit,
			'order' => [
				'ID' => 'asc'
			]
		]);
		while ($row = $res->fetch())
		{
			$row['FILE_ID'] *= -1;
			FileTable::delete($row['ID']);
			$deletedFiles[$row['FILE_ID']] = $row['FILE_ID'];
		}
		if (!empty($deletedFiles))
		{
			// don't delete still used
			$res = FileTable::getList([
				'select' => [
					'FILE_ID'
				],
				'filter' => [
					'FILE_ID' => $deletedFiles
				]
			]);
			while ($row = $res->fetch())
			{
				unset($deletedFiles[$row['FILE_ID']]);
			}
			foreach ($deletedFiles as $fid)
			{
				$fileData = self::getFileArray($fid);
				if ($fileData)
				{
					//@tmp log
					Debug::log(
						$fileData['SRC'],
						'fileId: ' . $fid,
						'LANDING_FILE_DELETE'
					);
					\CFile::delete($fid);
				}
			}
		}
	}

	/**
	 * Add new record for Site.
	 * @param int $id Site id.
	 * @param int $fileId File id.
	 * @param bool $temp This is temporary file.
	 * @return void
	 */
	public static function addToSite(int $id, int $fileId, bool $temp = false): void
	{
		if ($fileId > 0 && $id > 0)
		{
			self::add($fileId, $id, self::ENTITY_TYPE_SITE, $temp);
		}
	}

	/**
	 * Gets files id from site.
	 * @param int $siteId Site id.
	 * @return array
	 */
	public static function getFilesFromSite($siteId)
	{
		return self::getFiles(
			$siteId,
			self::ENTITY_TYPE_SITE
		);
	}

	/**
	 * Delete record from Site.
	 * @param int $id Site id.
	 * @param int|array $fileId File id (by default delete all files from landing).
	 * @return void
	 */
	public static function deleteFromSite($id, $fileId = array())
	{
		self::delete($fileId, $id, self::ENTITY_TYPE_SITE);
	}

	/**
	 * Add new record for Landing.
	 * @param int $lid Landing id.
	 * @param int $fileId File id.
	 * @return void
	 */
	public static function addToLanding($lid, $fileId)
	{
		if ($fileId > 0 && $lid > 0)
		{
			self::add($fileId, $lid, self::ENTITY_TYPE_LANDING);
		}
	}

	/**
	 * Gets files id from landing.
	 * @param int $landingId Landing id.
	 * @return array
	 */
	public static function getFilesFromLanding($landingId)
	{
		return self::getFiles(
			$landingId,
			self::ENTITY_TYPE_LANDING
		);
	}

	/**
	 * Delete record from Landing.
	 * @param int $lid Landing id.
	 * @param int|array $fileId File id (by default delete all files from landing).
	 * @return void
	 */
	public static function deleteFromLanding($lid, $fileId = array())
	{
		self::delete($fileId, $lid, self::ENTITY_TYPE_LANDING);
	}

	/**
	 * Add new record(s) for Block.
	 * @param int $blockId Block id.
	 * @param int|array $fileId File id (or file ids).
	 * @param bool $temp This is temporary file.
	 * @return void
	 */
	public static function addToBlock(int $blockId, $fileId, bool $temp = false): void
	{
		if ($blockId > 0)
		{
			if (!is_array($fileId))
			{
				$fileId = array($fileId);
			}
			foreach ($fileId as $fid)
			{
				if ($fid > 0)
				{
					self::add($fid, $blockId, self::ENTITY_TYPE_BLOCK, $temp);
				}
			}
		}
	}

	/**
	 * Add new record(s) for Block (old records will be deleted).
	 * @param int $blockId Block id.
	 * @param int|array $fileId File id (or file ids).
	 * @return void
	 */
	public static function replaceInBlock($blockId, $fileId)
	{
		if ($blockId > 0)
		{
			if (!is_array($fileId))
			{
				$fileId = array($fileId);
			}
			$res = FileTable::getList(array(
				'select' => array(
					'FILE_ID'
				),
				'filter' => array(
					'ENTITY_ID' => $blockId,
					'=ENTITY_TYPE' => self::ENTITY_TYPE_BLOCK
				)
			));
			while ($row = $res->fetch())
			{
				if (!in_array($row['FILE_ID'], $fileId))
				{
					self::delete($row['FILE_ID'], $blockId, self::ENTITY_TYPE_BLOCK);
				}
			}
			self::addToBlock($blockId, $fileId);
		}
	}

	/**
	 * Delete record from Block.
	 * @param int $blockId Block id.
	 * @param int|array $fileId File id (by default delete all files from block).
	 * @return void
	 */
	public static function deleteFromBlock($blockId, $fileId = array())
	{
		self::delete($fileId, $blockId, self::ENTITY_TYPE_BLOCK);
	}

	/**
	 * Parse some content for search data-fileid for the block.
	 * @param int $blockId Block id for content.
	 * @param string $content Content.
	 * @return array
	 */
	public static function getFilesFromBlockContent($blockId, $content)
	{
		$fileIds = array();
		// parse from content
		if (preg_match_all('/data-fileid[2x]{0,2}="([\d]+)"/i', $content, $matches))
		{
			foreach ($matches[1] as $fid)
			{
				$fileIds[] = $fid;
			}
		}
		// check if files ids set in blockId
		if (!empty($fileIds))
		{
			$res = FileTable::getList(array(
				'select' => array(
					'FILE_ID'
				),
				'filter' => array(
					'FILE_ID' => $fileIds,
					'ENTITY_ID' => $blockId,
					'=ENTITY_TYPE' => self::ENTITY_TYPE_BLOCK
				)
			));
			$fileIds = array();
			while ($row = $res->fetch())
			{
				$fileIds[] = $row['FILE_ID'];
			}
		}
		return $fileIds;
	}

	/**
	 * Gets files id from block.
	 * @param int $blockId Block id.
	 * @return array
	 */
	public static function getFilesFromBlock($blockId)
	{
		return self::getFiles(
			$blockId,
			self::ENTITY_TYPE_BLOCK
		);
	}

	/**
	 * Add new record for Asset.
	 * @param int $assetId Id of landing to which attached asset.
	 * @param int $fileId File id.
	 * @return void
	 */
	public static function addToAsset($assetId, $fileId): void
	{
		if ($fileId > 0 && $assetId > 0)
		{
			self::add($fileId, $assetId, self::ENTITY_TYPE_ASSET);
			self::markAssetRebuilt($assetId);
			// todo: res from add and check error
		}
	}

	/**
	 * Add new record for a markup upload buffer. The binding is not TEMP: the buffer
	 * has its own lifetime, an expired buffer releases its files itself.
	 * @param int $uploadId Id of the markup upload buffer row.
	 * @param int $fileId File id.
	 * @return void
	 */
	public static function addToMarkupUpload(int $uploadId, int $fileId): void
	{
		if ($fileId > 0 && $uploadId > 0)
		{
			self::add($fileId, $uploadId, self::ENTITY_TYPE_MARKUP_UPLOAD);
		}
	}

	/**
	 * Mark markup upload buffer files for deletion.
	 * Not removed from disk immediately, just marked for the agent.
	 * @param int $uploadId Id of the markup upload buffer row.
	 * @param int|int[] $fileId File id (by default delete all files of the buffer).
	 * @return void
	 */
	public static function deleteFromMarkupUpload(int $uploadId, $fileId = []): void
	{
		self::delete($fileId, $uploadId, self::ENTITY_TYPE_MARKUP_UPLOAD);
	}

	/**
	 * Deletes part files of a markup upload buffer at once, bypassing the deferred deletion queue:
	 * a part belongs to the buffer only and is useless after gluing, a refusal or the buffer expiry.
	 * A file bound to anything else stays.
	 * @param int $uploadId Id of the markup upload buffer row.
	 * @param int[] $fileIds Part file ids.
	 * @return void
	 */
	public static function deleteMarkupUploadParts(int $uploadId, array $fileIds): void
	{
		$fileIds = array_values(array_unique(array_filter(
			array_map('intval', $fileIds),
			static fn(int $fileId): bool => $fileId > 0,
		)));
		if ($uploadId <= 0 || !$fileIds)
		{
			return;
		}

		$bindings = FileTable::getList([
			'select' => ['ID'],
			'filter' => [
				'ENTITY_ID' => $uploadId,
				'=ENTITY_TYPE' => self::ENTITY_TYPE_MARKUP_UPLOAD,
				'@FILE_ID' => array_merge($fileIds, array_map(static fn(int $fileId): int => -$fileId, $fileIds)),
			],
		]);
		while ($binding = $bindings->fetch())
		{
			FileTable::delete($binding['ID']);
		}

		$deletable = array_fill_keys($fileIds, true);
		$stillUsed = FileTable::getList([
			'select' => ['FILE_ID'],
			'filter' => ['@FILE_ID' => $fileIds],
		]);
		while ($row = $stillUsed->fetch())
		{
			unset($deletable[(int)$row['FILE_ID']]);
		}
		if (!$deletable)
		{
			return;
		}

		$files = \CFile::getList([], ['@ID' => array_keys($deletable), 'MODULE_ID' => 'landing']);
		while ($file = $files->fetch())
		{
			\CFile::delete((int)$file['ID']);
		}
	}

	/**
	 * Gets asset files for current landing.
	 * @param int $assetId Id of landing to which attached asset.
	 * @return array
	 */
	public static function getFilesFromAsset($assetId): array
	{
		return self::getFiles(
			$assetId,
			self::ENTITY_TYPE_ASSET
		);
	}

	/**
	 * Delete asset files for current landing.
	 * Not remove from disk immediately, just marked for agent
	 * @param int $assetId Id of landing to which attached asset.
	 * @param int|int[] $fileId File id (by default delete all files from Asset).
	 * @return void
	 */
	public static function deleteFromAsset(int $assetId, $fileId = []): void
	{
		self::delete($fileId, $assetId, self::ENTITY_TYPE_ASSET);
	}

	/**
	 * Mark file as "need rebuild", but not delete them. File will be exist until not created new file.
	 * @param int|int[] $assetId Id of landing to which attached asset. If not set - will marked all.
	 * @return bool
	 */
	public static function markAssetToRebuild($assetId = []): bool
	{
		$filter = [
			'=ENTITY_TYPE' => self::ENTITY_TYPE_ASSET
		];
		if ($assetId)
		{
			$filter['ENTITY_ID'] = $assetId;
		}

		$res = FileTable::getList([
			'select' => ['ID', 'ENTITY_ID'],
			'filter' => $filter
		]);
		$files = $res->fetchAll();
		$result = true;
		foreach ($files as $file)
		{
			$resUpdate = FileTable::update(
				$file['ID'],
				[
					'ENTITY_ID' => -1 * abs($file['ENTITY_ID'])
				]
			);
			$result = $result && $resUpdate->isSuccess();
		}

		return count($files) > 0 ? $result : false;
	}

	/**
	 * @deprecated Use markAssetRebuilt().
	 * @param int|int[] $assetId Id of landing to which attached asset.
	 * @return void
	 */
	public static function markAssetRebuilded($assetId): void
	{
		self::markAssetRebuilt($assetId);
	}

	/**
	 * When file rebuilt - delete old files (marked as "need rebuild") for current asset ID (current landing)
	 * @param int|int[] $assetId Id of landing to which attached asset.
	 * @return void
	 */
	public static function markAssetRebuilt($assetId): void
	{
		if(!is_array($assetId))
		{
			$assetId = [$assetId];
		}

		foreach ($assetId as $key => $id)
		{
			self::deleteFromAsset(-1 * abs($id));
		}
	}

	/**
	 * Copy files from one entity to another.
	 * @param int $from Entity id.
	 * @param int $to Entity id.
	 * @param string $entityType Entity type.
	 * @return void
	 */
	protected static function copyEntityFiles($from, $to, $entityType)
	{
		$res = FileTable::getList(array(
			'select' => array(
				'FILE_ID'
			),
			'filter' => array(
				'ENTITY_ID' => $from,
				'=ENTITY_TYPE' => $entityType
			)
		));
		while ($row = $res->fetch())
		{
			FileTable::add(array(
				'FILE_ID' => $row['FILE_ID'],
				'ENTITY_ID' => $to,
				'ENTITY_TYPE' => $entityType
			));
		}
	}

	/**
	 * Copy files from one site to another.
	 * @param int $from Site id.
	 * @param int $to Site id.
	 * @return void
	 */
	public static function copySiteFiles($from, $to)
	{
		self::copyEntityFiles($from, $to, self::ENTITY_TYPE_SITE);
	}

	/**
	 * Copy files from one landing to another.
	 * @param int $from Landing id.
	 * @param int $to Landing id.
	 * @return void
	 */
	public static function copyLandingFiles($from, $to)
	{
		self::copyEntityFiles($from, $to, self::ENTITY_TYPE_LANDING);
	}

	/**
	 * Copy files from one block to another.
	 * @param int $from Block id.
	 * @param int $to Block id.
	 * @return void
	 */
	public static function copyBlockFiles($from, $to)
	{
		self::copyEntityFiles($from, $to, self::ENTITY_TYPE_BLOCK);
	}

	/**
	 * Gets core file array.
	 * @param int $fileId File id.
	 * @return mixed
	 */
	public static function getFileArray($fileId)
	{
		$file = \CFile::getFileArray(
			$fileId
		);
		if (
			isset($file['MODULE_ID']) &&
			$file['MODULE_ID'] == 'landing'
		)
		{
			return $file;
		}
		return false;
	}

	/**
	 * Gets core file path.
	 * @param int $fileId File id.
	 * @return string|null
	 */
	public static function getFilePath($fileId): ?string
	{
		$file = self::getFileArray($fileId);
		if (isset($file['SRC']))
		{
			return $file['SRC'];
		}
		return null;
	}

	/**
	 * Delete all file Id from File table.
	 * @param int $fileId File id to delete.
	 * @return void
	 */
	public static function releaseFile(int $fileId): void
	{
		$res = FileTable::getList(array(
			'select' => [
				'ID'
			],
			'filter' => [
				'FILE_ID' => $fileId
			]
		));
		while ($row = $res->fetch())
		{
			FileTable::delete($row['ID']);
		}
	}

	/**
	 * Physical delete file.
	 * @param int $fileId File id.
	 * @return void
	 */
	public static function deletePhysical(int $fileId): void
	{
		if (self::getFileArray($fileId))
		{
			self::releaseFile($fileId);
			\CFile::delete($fileId);
		}
	}
}