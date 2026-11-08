<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Draft;

use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Mail\Helper\Attachment\Storage;
use Bitrix\Mail\Integration\Crm\DraftUploader;
use Bitrix\Mail\Internal\Entity\Draft\DraftAttachmentSource;
use Bitrix\Mail\Internals\DraftAttachmentTable;
use Bitrix\Mail\Internals\DraftTable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;
use Bitrix\UI\FileUploader\ControllerResolver;
use Bitrix\UI\FileUploader\Uploader;
use Bitrix\UI\FileUploader\UploaderController;

class AttachmentStorage
{
	private const ORPHAN_BATCH_SIZE = 100;

	/**
	 * The mobile compose form names its upload endpoint by this very string
	 * (mailmobile/install/mobileapp/mailmobile/extensions/mail/const/src/ajax.js), so the controller is
	 * asked for by name here as well. A name is not a class, so mail keeps no dependency on the module
	 * that owns the endpoint and the two are released in any order.
	 */
	private const MOBILE_UPLOADER_CONTROLLER = 'mailmobile.FileUploader.MailUploaderController';

	/** @param DraftAttachmentSource[] $sources */
	public function prepare(
		int $userId,
		array $sources,
		?int $draftId,
		?string $contextType = null,
		?int $crmEntityTypeId = null,
		?int $crmEntityId = null,
	): ?array
	{
		if ($sources === [])
		{
			return [
				'attachments' => [],
				'createdFileIds' => [],
				'previousFileIds' => $this->loadDraftFileIds($userId, $draftId),
			];
		}
		if (!Loader::includeModule('disk'))
		{
			return null;
		}

		$draftBindings = $this->loadDraftBindings($userId, $draftId);
		if ($draftBindings === null)
		{
			return null;
		}
		$ownedBindings = $this->selectOwnedBindings($sources, $draftBindings);
		if ($ownedBindings === null)
		{
			return null;
		}

		$targetFolder = null;
		foreach ($sources as $source)
		{
			if ($source->source !== 'draft')
			{
				$targetFolder = $this->getTargetFolder($userId);
				if ($targetFolder === null)
				{
					return null;
				}

				break;
			}
		}

		$uploaderController = $this->hasPendingUpload($sources)
			? $this->createUploaderController($contextType, $crmEntityTypeId, $crmEntityId)
			: null
		;

		$attachments = [];
		$createdFileIds = [];
		foreach ($sources as $sort => $source)
		{
			if ($source->source === 'draft')
			{
				$attachments[] = $ownedBindings[(int)$source->id] + ['SORT' => ($sort + 1) * 100];

				continue;
			}

			$copyData = $this->copySourceToDraftFolder($userId, $source, $targetFolder, $uploaderController);
			if ($copyData === null)
			{
				$this->deleteFiles($createdFileIds);

				return null;
			}

			['copy' => $copy, 'sourceObjectId' => $sourceObjectId, 'sourceFileId' => $sourceFileId] = $copyData;
			$createdFileIds[] = (int)$copy->getFileId();
			$fileData = \CFile::getFileArray($copy->getFileId());
			$attachments[] = [
				'FILE_ID' => (int)$copy->getFileId(),
				'SOURCE_OBJECT_ID' => $sourceObjectId,
				'SOURCE_FILE_ID' => $sourceFileId,
				'FILE_NAME' => (string)$copy->getName(),
				'FILE_SIZE' => (int)$copy->getSize(),
				'CONTENT_TYPE' => $fileData['CONTENT_TYPE'] ?? null,
				'SORT' => ($sort + 1) * 100,
			];
		}

		return [
			'attachments' => $attachments,
			'createdFileIds' => $createdFileIds,
			'previousFileIds' => array_map(
				static fn(array $binding): int => (int)$binding['FILE_ID'],
				$draftBindings,
			),
		];
	}

	public function prepareTransferred(array $attachments): ?array
	{
		if ($attachments === [])
		{
			return [];
		}
		if (!Loader::includeModule('disk'))
		{
			return null;
		}

		$prepared = [];
		foreach ($attachments as $sort => $attachment)
		{
			$fileId = (int)($attachment['FILE_ID'] ?? 0);
			$file = $fileId > 0 ? \CFile::getFileArray($fileId) : false;
			if (!is_array($file))
			{
				return null;
			}

			$object = Storage::getObjectByAttachment([
				'FILE_ID' => $fileId,
				'FILE_NAME' => (string)($attachment['FILE_NAME'] ?? $file['ORIGINAL_NAME'] ?? ''),
				'FILE_SIZE' => (int)($file['FILE_SIZE'] ?? 0),
			], true);
			if (!$object instanceof File)
			{
				return null;
			}

			$sourceObjectId = (int)($attachment['SOURCE_OBJECT_ID'] ?? 0);
			$prepared[] = [
				'FILE_ID' => $fileId,
				'SOURCE_OBJECT_ID' => $sourceObjectId > 0 ? $sourceObjectId : (int)$object->getId(),
				'SOURCE_FILE_ID' => $fileId,
				'FILE_NAME' => (string)($attachment['FILE_NAME'] ?? $object->getName()),
				'FILE_SIZE' => (int)($file['FILE_SIZE'] ?? 0),
				'CONTENT_TYPE' => $file['CONTENT_TYPE'] ?? null,
				'CONTENT_ID' => isset($attachment['CONTENT_ID']) ? (string)$attachment['CONTENT_ID'] : null,
				'IS_INLINE' => !empty($attachment['IS_INLINE']),
				'SORT' => ($sort + 1) * 100,
			];
		}

		return $prepared;
	}

	private function copySourceToDraftFolder(
		int $userId,
		DraftAttachmentSource $source,
		Folder $targetFolder,
		?UploaderController $uploaderController,
	): ?array
	{
		$objectId = $this->resolveDiskObjectId($source);
		if ($objectId !== null)
		{
			$file = File::loadById($objectId, ['STORAGE']);
			if ($file === null || !$file->canRead($file->getStorage()->getSecurityContext($userId)))
			{
				return null;
			}

			$sourceFile = $file->getRealObject();
			$copy = $sourceFile->copyTo($targetFolder, $userId, true);

			return $copy instanceof File
				? [
					'copy' => $copy,
					'sourceObjectId' => (int)$sourceFile->getId(),
					'sourceFileId' => (int)$sourceFile->getFileId(),
				]
				: null;
		}

		if (
			$source->source !== 'upload'
			|| $uploaderController === null
			|| (int)(CurrentUser::get()?->getId()) !== $userId
		)
		{
			return null;
		}

		$pendingFiles = (new Uploader($uploaderController))->getPendingFiles([$source->id]);
		foreach ($pendingFiles as $pendingFile)
		{
			$fileId = (int)$pendingFile->getFileId();
			$fileArray = $fileId > 0 ? \CFile::makeFileArray($fileId) : false;
			if (!$pendingFile->isValid() || !is_array($fileArray))
			{
				return null;
			}

			$copy = $targetFolder->uploadFile(
				$fileArray,
				[
					'NAME' => (string)$fileArray['name'],
					'CREATED_BY' => $userId,
				],
				[],
				true,
			);

			return $copy instanceof File
				? ['copy' => $copy, 'sourceObjectId' => 0, 'sourceFileId' => $fileId]
				: null;
		}

		return null;
	}

	private function resolveDiskObjectId(DraftAttachmentSource $source): ?int
	{
		if (preg_match('/^n([1-9]\d*)$/', $source->id, $matches) || ctype_digit($source->id))
		{
			return (int)($matches[1] ?? $source->id);
		}

		return null;
	}

	/** @param DraftAttachmentSource[] $sources */
	private function hasPendingUpload(array $sources): bool
	{
		foreach ($sources as $source)
		{
			if ($source->source === 'upload' && $this->resolveDiskObjectId($source) === null)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The compose form picks its upload endpoint by context, and a pending token is signed with the
	 * name and the options of the controller that issued it. So the controller of the same context
	 * has to be rebuilt here, or the signature of a legitimate token never validates.
	 */
	private function createUploaderController(
		?string $contextType,
		?int $crmEntityTypeId,
		?int $crmEntityId,
	): ?UploaderController
	{
		if (!Loader::includeModule('ui'))
		{
			return null;
		}

		if ($contextType === DraftTable::CONTEXT_CRM)
		{
			return DraftUploader::createController((int)$crmEntityTypeId, (int)$crmEntityId);
		}

		return Loader::includeModule('mailmobile')
			? ControllerResolver::createController(self::MOBILE_UPLOADER_CONTROLLER)
			: null
		;
	}

	public function deleteFiles(array $fileIds): void
	{
		foreach (array_unique(array_map('intval', $fileIds)) as $fileId)
		{
			if ($fileId > 0)
			{
				try
				{
					Storage::unregisterAttachment($fileId);
				}
				catch (\Throwable)
				{
					// Orphan cleanup retries the owned file later.
				}
			}
		}
	}

	public function cleanupOrphans(): void
	{
		if (!Loader::includeModule('disk'))
		{
			return;
		}

		$storage = Storage::getStorage();
		if ($storage === false)
		{
			return;
		}

		$draftFolder = $storage->getChild(['=NAME' => 'draft', '=TYPE' => ObjectTable::TYPE_FOLDER]);
		if (!$draftFolder instanceof Folder)
		{
			return;
		}

		$cutoff = (new DateTime())->add('-24 hours');
		$monthFolders = ObjectTable::query()
			->setSelect(['ID'])
			->where('PARENT_ID', $draftFolder->getId())
			->where('DELETED_TYPE', ObjectTable::DELETED_TYPE_NONE)
			->where('TYPE', ObjectTable::TYPE_FOLDER)
			->where('CREATE_TIME', '<=', $cutoff)
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;
		if ($monthFolders === [])
		{
			return;
		}
		$rotation = ((int)date('z') * 24 + (int)date('G')) % count($monthFolders);
		$monthFolderId = (int)$monthFolders[$rotation]['ID'];

		// Anti-join keeps bound files out of the batch, so still used attachments never block orphan progress.
		$files = ObjectTable::query()
			->setSelect(['ID'])
			->registerRuntimeField(
				new Reference(
					'DRAFT_BINDING',
					DraftAttachmentTable::class,
					Join::on('this.FILE_ID', 'ref.FILE_ID'),
					['join_type' => Join::TYPE_LEFT],
				),
			)
			->where('PARENT_ID', $monthFolderId)
			->where('DELETED_TYPE', ObjectTable::DELETED_TYPE_NONE)
			->where('TYPE', ObjectTable::TYPE_FILE)
			->where('CREATE_TIME', '<=', $cutoff)
			->whereNull('DRAFT_BINDING.ID')
			->setOrder(['ID' => 'ASC'])
			->setLimit(self::ORPHAN_BATCH_SIZE)
			->fetchAll()
		;
		foreach ($files as $file)
		{
			File::loadById((int)$file['ID'])?->delete(1);
		}
	}

	private function getTargetFolder(int $userId): ?Folder
	{
		$storage = Storage::getStorage();
		if ($storage === false)
		{
			return null;
		}

		$draftFolder = $storage->getChild(['=NAME' => 'draft', '=TYPE' => ObjectTable::TYPE_FOLDER]);
		if (!$draftFolder instanceof Folder)
		{
			$draftFolder = $storage->addFolder(['NAME' => 'draft', 'CREATED_BY' => $userId]);
		}
		if (!$draftFolder instanceof Folder)
		{
			return null;
		}

		$month = date('Y-m');
		$monthFolder = $draftFolder->getChild(['=NAME' => $month, '=TYPE' => ObjectTable::TYPE_FOLDER]);
		if (!$monthFolder instanceof Folder)
		{
			$monthFolder = $draftFolder->addSubFolder(['NAME' => $month, 'CREATED_BY' => $userId]);
		}

		return $monthFolder instanceof Folder ? $monthFolder : null;
	}

	/**
	 * @param DraftAttachmentSource[] $sources
	 *
	 * @return array<int, array>|null binding rows indexed by binding id, null when any of them is not owned
	 */
	private function loadDraftBindings(int $userId, ?int $draftId): ?array
	{
		if ($draftId === null)
		{
			return [];
		}
		if ($draftId <= 0)
		{
			return null;
		}

		$draft = DraftTable::query()
			->setSelect(['ID'])
			->where('ID', $draftId)
			->where('USER_ID', $userId)
			->setLimit(1)
			->fetch()
		;
		if ($draft === false)
		{
			return null;
		}

		$bindings = [];
		$rows = DraftAttachmentTable::query()
			->setSelect([
				'ID',
				'FILE_ID',
				'SOURCE_OBJECT_ID',
				'SOURCE_FILE_ID',
				'FILE_NAME',
				'FILE_SIZE',
				'CONTENT_TYPE',
			])
			->where('DRAFT_ID', $draftId)
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$bindingId = (int)$row['ID'];
			unset($row['ID']);
			$bindings[$bindingId] = $row;
		}

		return $bindings;
	}

	private function loadDraftFileIds(int $userId, ?int $draftId): array
	{
		$bindings = $this->loadDraftBindings($userId, $draftId);
		if ($bindings === null)
		{
			return [];
		}

		return array_map(
			static fn(array $binding): int => (int)$binding['FILE_ID'],
			$bindings,
		);
	}

	/**
	 * @param DraftAttachmentSource[] $sources
	 * @param array<int, array> $draftBindings
	 *
	 * @return array<int, array>|null
	 */
	private function selectOwnedBindings(array $sources, array $draftBindings): ?array
	{
		$bindingIds = [];
		foreach ($sources as $source)
		{
			if ($source->source === 'draft')
			{
				$bindingIds[(int)$source->id] = (int)$source->id;
			}
		}
		if ($bindingIds === [])
		{
			return [];
		}
		if (min($bindingIds) <= 0)
		{
			return null;
		}

		$ownedBindings = array_intersect_key($draftBindings, $bindingIds);

		return count($ownedBindings) === count($bindingIds) ? $ownedBindings : null;
	}
}
