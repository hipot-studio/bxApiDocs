<?php
namespace Bitrix\Landing\Connector;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

class Disk
{
	public const FILE_PREFIX_HREF = '#diskFile';
	public const FILE_MASK_HREF = '(file:)?#diskFile([\d]+)';

	public const ERROR_FILE_DENIED = 'DISK_FILE_DENIED';
	public const ERROR_FILE_TOO_BIG = 'DISK_FILE_TOO_BIG';

	/**
	 * Returns regexp for the link to the exact file. Unlike self::FILE_MASK_HREF, which matches a link
	 * to any file, it matches the given id only, so a link to an id with the same prefix does not match.
	 *
	 * @param int $objectId Disk's object id (file id).
	 * @return string
	 */
	public static function getExactFileHrefPattern(int $objectId): string
	{
		return '/' . preg_quote(self::FILE_PREFIX_HREF . $objectId, '/') . '(?![\d])/i';
	}

	/**
	 * Checks disk's files within content for read access. If not, removes file's marks.
	 *
	 * @param string $content Content.
	 * @param string|null $oldContent Old content (if exists, found old files will not be replaced).
	 * @param bool &$replaced True if was replaced.
	 * @return string
	 */
	public static function sanitizeContent(string $content, ?string $oldContent = null, bool &$replaced = false): string
	{
		if (!\Bitrix\Main\Loader::includeModule('disk'))
		{
			return $content;
		}

		$existsFiles = [];

		if ($oldContent)
		{
			if (preg_match_all('/' . self::FILE_MASK_HREF . '/i', $oldContent, $matches))
			{
				foreach ($matches[2] as $objectId)
				{
					$existsFiles[] = $objectId;
				}
			}
		}

		if (preg_match_all('/' . self::FILE_MASK_HREF . '/i', $content, $matches))
		{
			foreach ($matches[2] as $i => $objectId)
			{
				if ($oldContent && in_array($objectId, $existsFiles))
				{
					continue;
				}

				$file = \Bitrix\Disk\BaseObject::loadById($objectId);
				if ($file)
				{
					$securityContext = $file->getStorage()->getCurrentUserSecurityContext();
					if ($file->canRead($securityContext))
					{
						continue;
					}
				}

				$replaced = true;
				$content = str_replace($matches[0][$i], '""', $content);
			}
		}

		return $content;
	}

	/**
	 * Returns file's info by Disk's file id.
	 *
	 * @param int $objectId Disk's object if (file id).
	 * @param bool $checkRights True for checking permissions within Disk.
	 * @param bool $copyInLocalStorage If file in external folder then copies file in local user storage.
	 * @return array|null
	 */
	public static function getFileInfo(int $objectId, bool $checkRights = true, bool $copyInLocalStorage = false): ?array
	{
		if ($objectId && \Bitrix\Main\Loader::includeModule('disk'))
		{
			$file = \Bitrix\Disk\BaseObject::loadById($objectId);
			if ($file)
			{
				if ($checkRights)
				{
					$securityContext = $file->getStorage()->getCurrentUserSecurityContext();
					if (!$file->canRead($securityContext))
					{
						return null;
					}
				}

				if ($copyInLocalStorage)
				{
					$currentUserId = \Bitrix\Landing\Manager::getUserId();
					$userStorage = \Bitrix\Disk\Driver::getInstance()->getStorageByUserId($currentUserId);
					if (!$userStorage)
					{
						return null;
					}

					$folder = $userStorage->getFolderForSavedFiles();
					if (!$folder)
					{
						return null;
					}

					if (
						$file->getStorageId() !== $userStorage->getId() ||
						(int)$file->getCreateUser()->getId() !== $currentUserId
					)
					{
						$newFile = $file->getRealObject()->copyTo($folder, $currentUserId, true);
						if ($file->getRealObject()->getName() != $file->getName())
						{
							$newFile->renameInternal($file->getName(), true);
						}
						if (!$newFile)
						{
							return null;
						}

						return [
							'ID' => $newFile->getRealObject()->getFileId(),
							'OBJECT_ID' => $newFile->getId(),
							'NAME' => $newFile->getName()
						];
					}
				}

				return [
					'ID' => $file->getRealObject()->getFileId(),
					'OBJECT_ID' => $file->getId(),
					'NAME' => $file->getName()
				];
			}
		}

		return null;
	}

	/**
	 * Reads the content of a Disk file on behalf of the user: the file must be readable
	 * for the user in the security context of its storage. A missing object, a folder,
	 * an object in the recycle bin, a denied one and an absent disk module all answer
	 * ERROR_FILE_DENIED, so a caller learns nothing about foreign objects.
	 *
	 * @param int $objectId Disk's object id (file id).
	 * @param int $userId User the file is read for.
	 * @param int $maxSize Max file size in bytes to read, 0 - no limit; a bigger file answers ERROR_FILE_TOO_BIG.
	 * @return Result Success data: ['name' => string, 'content' => string, 'size' => int].
	 */
	public static function readFileForUser(int $objectId, int $userId, int $maxSize = 0): Result
	{
		$result = new Result();
		$denied = new Error('The disk file is not available.', self::ERROR_FILE_DENIED);

		if ($objectId <= 0 || $userId <= 0 || !static::includeDiskModule())
		{
			return $result->addError($denied);
		}

		$file = \Bitrix\Disk\BaseObject::loadById($objectId);
		if (!($file instanceof \Bitrix\Disk\File) || $file->isDeleted())
		{
			return $result->addError($denied);
		}

		$storage = $file->getStorage();
		if (!$storage || !$file->canRead($storage->getSecurityContext($userId)))
		{
			return $result->addError($denied);
		}

		if ($maxSize > 0 && (int)$file->getSize() > $maxSize)
		{
			return $result->addError(new Error('The disk file exceeds the size limit.', self::ERROR_FILE_TOO_BIG));
		}

		$fileArray = \CFile::makeFileArray($file->getFileId());
		$content = is_array($fileArray) && !empty($fileArray['tmp_name'])
			? @file_get_contents($fileArray['tmp_name'])
			: false
		;
		if ($content === false)
		{
			return $result->addError($denied);
		}
		if ($maxSize > 0 && strlen($content) > $maxSize)
		{
			return $result->addError(new Error('The disk file exceeds the size limit.', self::ERROR_FILE_TOO_BIG));
		}

		return $result->setData([
			'name' => (string)$file->getName(),
			'content' => $content,
			'size' => strlen($content),
		]);
	}

	/**
	 * The disk module is optional for landing.
	 */
	protected static function includeDiskModule(): bool
	{
		return \Bitrix\Main\Loader::includeModule('disk');
	}
}
