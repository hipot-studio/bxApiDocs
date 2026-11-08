<?php

namespace Bitrix\Disk\ZipNginx;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\Type\ObjectCollection;
use Bitrix\Disk\Driver;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\ModuleManager;

class Archive extends Response\Zip\Archive
{
	private const MAX_FILES_IN_ARCHIVE = 1000;

	public static function createByObjects(
		string $name,
		ObjectCollection $objectCollection,
		int $userId,
		bool $skipRootFolder = false,
	): static
	{
		$entryBuilder = new Response\Zip\EntryBuilder();

		$archive = new static($name . '.zip');
		foreach ($objectCollection as $object)
		{
			if ($object instanceof Folder)
			{
				$securityContext = $object->getStorage()?->getSecurityContext($userId);
				if (!$securityContext)
				{
					continue;
				}

				if (!$skipRootFolder && $archive->isPossibleUseEmptyDirectory())
				{
					$directory = $entryBuilder->createEmptyDirectory($object->getName());
					$archive->addEntry($directory);
				}

				$archive->collectDescendants(
					folder: $object,
					securityContext: $securityContext,
					currentPath: $skipRootFolder ? '' : $object->getName() . '/',
				);
			}
			if ($object instanceof File)
			{
				$archive->addEntry(ArchiveEntry::createFromFileModel($object));
			}
		}

		return $archive;
	}

	public static function createByUnifiedObjects(
		string $name,
		ObjectCollection $objectCollection,
		bool $skipRootFolder = false,
	): static
	{
		$entryBuilder = new Response\Zip\EntryBuilder();
		$archive = new static($name . '.zip');

		foreach ($objectCollection as $object)
		{
			if ($object instanceof Folder)
			{
				if (!$skipRootFolder && $archive->isPossibleUseEmptyDirectory())
				{
					$directory = $entryBuilder->createEmptyDirectory($object->getName());
					$archive->addEntry($directory);
				}

				$archive->collectDescendantsWithoutRightsCheck(
					$object,
					$skipRootFolder ? '' : $object->getName() . '/',
				);
			}

			if ($object instanceof File)
			{
				$archive->addEntry(ArchiveEntry::createFromFileModel($object));
			}
		}

		return $archive;
	}

	/**
	 * Creates archive which will be copy of folder.
	 * @param Folder          $folder Target folder.
	 * @param SecurityContext $securityContext Security context to getting items.
	 * @return static
	 */
	public static function createFromFolder(Folder $folder, SecurityContext $securityContext)
	{
		$archive = new static($folder->getName() . '.zip');
		$archive->collectDescendants($folder, $securityContext);

		return $archive;
	}

	private function collectDescendants(Folder $folder, SecurityContext $securityContext, string $currentPath = ''): void
	{
		$entryBuilder = new Response\Zip\EntryBuilder();

		foreach ($folder->getChildren($securityContext) as $object)
		{
			if ($object instanceof Folder)
			{
				if ($this->isPossibleUseEmptyDirectory())
				{
					$directory = $entryBuilder->createEmptyDirectory($currentPath . $object->getName());
					$this->addEntry($directory);
				}

				$this->collectDescendants(
					$object,
					$securityContext,
					$currentPath . $object->getName() . '/'
				);

			}
			elseif ($object instanceof File)
			{
				$this->addEntry(ArchiveEntry::createFromFileModel($object, $currentPath . $object->getName()));
			}
		}
	}

	protected function collectDescendantsWithoutRightsCheck(Folder $folder, string $currentPath = ''): void
	{
		$entryBuilder = new Response\Zip\EntryBuilder();

		$query = ObjectTable::getChildren(
			objectId: $folder->getId(),
			parameters: [
				'filter' => [
					'DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
				],
			],
		);

		while ($row = $query->fetch())
		{
			$object = BaseObject::buildFromArray($row);

			if ($object instanceof Folder)
			{
				if ($this->isPossibleUseEmptyDirectory())
				{
					$directory = $entryBuilder->createEmptyDirectory($currentPath . $object->getName());
					$this->addEntry($directory);
				}

				$this->collectDescendantsWithoutRightsCheck(
					$object,
					$currentPath . $object->getName() . '/',
				);
			}
			elseif ($object instanceof File)
			{
				$this->addEntry(ArchiveEntry::createFromFileModel($object, $currentPath . $object->getName()));
			}
		}
	}

	private function isPossibleUseEmptyDirectory(): bool
	{
		//right now we can use empty directory only in bitrix24, because we know that version of mod_zip is 1.3.0
		//in future we can check version of mod_zip and use empty directory
		return ModuleManager::isModuleInstalled('bitrix24');
	}

	/**
	 * Sends content to output stream and sets necessary headers.
	 *
	 * @return void
	 */
	public function send(): void
	{
		if ($this->isEmpty())
		{
			$this->getHeaders()->delete('X-Archive-Files');

			$emptyArchive = new EmptyArchive($this->name);
			$emptyArchive->copyHeadersTo($this);
			$emptyArchive->send();
		}
		else
		{
			parent::send();
		}
	}

	/**
	 * Checks if the total number of files in the given object collection exceeds the maximum allowed.
	 *
	 * @param ObjectCollection $objectCollection Collection of files and folders to check.
	 * @param int $userId User ID for security context resolution.
	 *
	 * @return bool True if the file limit is exceeded, false otherwise.
	 */
	public static function isFileLimitExceededByObjects(ObjectCollection $objectCollection, int $userId): bool
	{
		$fileCount = 0;
		$maxFilesInArchive = self::getMaxFilesInArchive();

		foreach ($objectCollection as $object)
		{
			if ($object instanceof File)
			{
				$fileCount++;
			}

			if ($object instanceof Folder)
			{
				$securityContext = $object->getStorage()?->getSecurityContext($userId);
				if ($securityContext)
				{
					$fileCount += self::countFilesInFolder($object, $securityContext);
				}
			}

			if ($fileCount > $maxFilesInArchive)
			{
				return true;
			}
		}

		return false;
	}

	public static function isFileLimitExceededByUnifiedObjects(ObjectCollection $objectCollection): bool
	{
		$fileCount = 0;
		$maxFilesInArchive = self::getMaxFilesInArchive();

		foreach ($objectCollection as $object)
		{
			if ($object instanceof File)
			{
				$fileCount++;
			}

			if ($object instanceof Folder)
			{
				$fileCount += self::countFilesInFolder(
					folder: $object,
					limit: $maxFilesInArchive - $fileCount,
					shouldDisableRightsCheck: true,
				);
			}

			if ($fileCount > $maxFilesInArchive)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks if the number of files in the specified folder (including descendants) exceeds the maximum allowed.
	 *
	 * @param Folder $folder Target folder to check.
	 * @param SecurityContext $securityContext Security context for accessing folder contents.
	 *
	 * @return bool True if the file limit is exceeded, false otherwise.
	 */
	public static function isFileLimitExceededByFolder(Folder $folder, SecurityContext $securityContext): bool
	{
		$fileCount = self::countFilesInFolder($folder, $securityContext);

		return $fileCount > self::getMaxFilesInArchive();
	}

	/**
	 * Counts the number of files in the given folder and its descendants, using the provided security context.
	 * If the number of files exceeds the maximum allowed, the method may return a value greater than the real count.
	 *
	 * @param Folder $folder Target folder to count files in.
	 * @param SecurityContext|null $securityContext Security context for accessing folder contents.
	 * @param int|null $limit Maximum file count to check.
	 * @param bool $shouldDisableRightsCheck Whether rights check should be disabled.
	 *
	 * @return int Number of files found, or a value greater than the real count if the limit is exceeded.
	 */
	private static function countFilesInFolder(
		Folder $folder,
		?SecurityContext $securityContext = null,
		?int $limit = null,
		bool $shouldDisableRightsCheck = false,
	): int
	{
		$realFolder = $folder->getRealObject();

		if (!$realFolder)
		{
			return 0;
		}

		$maxFilesInArchive = $limit ?? self::getMaxFilesInArchive();

		$parameters = [
			'select' => ['ID'],
			'filter' => [
				'TYPE' => ObjectTable::TYPE_FILE,
				'DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
			],
			'limit' => $maxFilesInArchive + 1,
		];

		if (!$shouldDisableRightsCheck)
		{
			if (!$securityContext)
			{
				return 0;
			}

			$parameters =
				Driver::getInstance()
					->getRightsManager()
					->addRightsCheck(
						securityContext: $securityContext,
						parameters: $parameters,
						specificColumns: ['ID', 'CREATED_BY'],
					)
			;
		}

		$fileCount = 0;
		$query = ObjectTable::getDescendants($realFolder->getId(), $parameters);
		while ($query->fetch())
		{
			$fileCount++;
			if ($fileCount > $maxFilesInArchive)
			{
				break;
			}
		}

		return $fileCount;
	}

	/**
	 * Returns the maximum number of files allowed in the archive.
	 * Gets value from option 'max_files_in_archive' or uses default from constant.
	 *
	 * @return int
	 */
	public static function getMaxFilesInArchive(): int
	{
		return (int)Option::get('disk', 'max_files_in_archive', self::MAX_FILES_IN_ARCHIVE);
	}
}
