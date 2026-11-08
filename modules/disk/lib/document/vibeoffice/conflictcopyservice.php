<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Repository\UnifiedLinkAccessRepository;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\RightsManager;
use Bitrix\Disk\Ui\Text;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class ConflictCopyService
{
	/** The bound copy cannot receive the editor result any more: removed, trashed, moved or read-only. */
	public const ERROR_STALE_COPY = 'vibeoffice_conflict_copy_stale';

	/** The caller owns the document transaction and commits the copy together with the receipt. */
	public function save(File $original, array $stagedFile, int $ownerId, ?int $existingCopyId): Result
	{
		if ($ownerId <= 0)
		{
			return $this->failure('A trusted conflict copy owner is required.');
		}
		$driver = Driver::getInstance();
		$storage = $driver->getStorageByUserId($ownerId) ?: $driver->addUserStorage($ownerId);
		if (!$storage)
		{
			return $this->failure('Could not open the conflict copy storage.');
		}
		$securityContext = $storage->getSecurityContext($ownerId);
		if ($existingCopyId)
		{
			$query = ObjectTable::query()->setSelect(['*'])->where('ID', $existingCopyId);
			$row = DocumentStateRepository::fetchForUpdate($query);
			$copy = $row ? File::buildFromArray($row) : null;
			if (
				!$copy || $copy->isDeleted() || (int)$copy->getId() === (int)$original->getId()
				|| (int)$copy->getStorageId() !== (int)$storage->getId()
				|| (int)$copy->getCreatedBy() !== $ownerId || !$copy->canUpdate($securityContext)
			)
			{
				return $this->failure('The conflict copy is no longer writable.', self::ERROR_STALE_COPY);
			}
			$version = $copy->addVersion($stagedFile, $ownerId, true, ['commentAttachedObjects' => false]);
			if (!$version || (int)$copy->getFileId() !== (int)$stagedFile['ID'])
			{
				return $this->failure('Could not save the conflict copy version.');
			}

			return (new Result())->setData(['file' => $copy, 'versionId' => (int)$version->getId()]);
		}

		$folder = $storage->getRootObject();
		if (!$folder || !$folder->canAdd($securityContext))
		{
			return $this->failure('The conflict copy folder is not writable.');
		}
		if (\Bitrix\Disk\Configuration::isEnabledStorageSizeRestriction() && !$storage->isPossibleToUpload((int)$stagedFile['FILE_SIZE']))
		{
			return $this->failure('The conflict copy exceeds the storage quota.');
		}
		$rightsManager = $driver->getRightsManager();
		$ownerCode = 'U' . $ownerId;
		$fullTask = $rightsManager->getTaskIdByName(RightsManager::TASK_FULL);
		$rights = [];
		foreach ($rightsManager->getAllListNormalizeRights($folder) as $right)
		{
			if ($right['ACCESS_CODE'] === $ownerCode && (int)$right['TASK_ID'] === (int)$fullTask)
			{
				continue;
			}
			$rights[] = [
				'ACCESS_CODE' => $right['ACCESS_CODE'],
				'TASK_ID' => (int)$right['TASK_ID'],
				'NEGATIVE' => 1,
			];
		}
		$rights[] = ['ACCESS_CODE' => $ownerCode, 'TASK_ID' => $fullTask, 'NEGATIVE' => 0];
		$extension = $original->getExtension();
		$name = Loc::getMessage('DISK_VIBEOFFICE_CONFLICT_COPY_NAME', [
			'#NAME#' => $extension === '' ? $original->getName() : mb_substr($original->getName(), 0, -mb_strlen($extension) - 1),
		]);
		if (!$name)
		{
			return $this->failure('The conflict copy name is unavailable.');
		}
		$copy = $folder->addFile([
			'NAME' => Text::correctFilename($name . ($extension === '' ? '' : '.' . $extension)),
			'FILE_ID' => (int)$stagedFile['ID'],
			'SIZE' => (int)$stagedFile['FILE_SIZE'],
			'CREATED_BY' => $ownerId,
		], $rights, true);
		if (!$copy || $rightsManager->getErrors())
		{
			return $this->failure('Could not create a private conflict copy.');
		}
		$access = UnifiedLinkAccessRepository::set((int)$copy->getId(), UnifiedLinkAccessLevel::Denied);
		if (!$access->isSuccess())
		{
			return (new Result())->addErrors($access->getErrors());
		}

		return (new Result())->setData(['file' => $copy, 'versionId' => null]);
	}

	private function failure(string $message, string $code = 'vibeoffice_conflict_copy_failed'): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
