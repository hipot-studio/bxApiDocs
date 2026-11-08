<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Disk;

use Bitrix\Crm\Integration\Disk\MailTemplateConnector;
use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Main\Loader;

final class MailTemplateAttachmentProvider
{
	private const ATTACHMENT_BATCH_SIZE = 300;

	/**
	 * The manager of the user fields of Disk is asked for lazily: the module itself is checked inside
	 * `prepare()`, so the dependency cannot be built when this class is. It is a parameter all the same, so
	 * that it stays visible and replaceable instead of being reached for through a singleton.
	 *
	 * @param (\Closure(): object)|null $userFieldManagerFactory
	 */
	public function __construct(
		private readonly ?\Closure $userFieldManagerFactory = null,
	)
	{
	}

	/**
	 * @param list<int> $attachmentIds
	 * @return array{0: string, 1: list<array{id: int, name: string, size: int, contentType: string|null}>}
	 */
	public function prepare(
		string $bodyHtml,
		array $attachmentIds,
		int $userId,
		int $templateId,
	): array
	{
		if ($attachmentIds === [] || !Loader::includeModule('disk'))
		{
			return [$bodyHtml, []];
		}

		$attachmentCandidates = [];
		$filesByObjectId = [];
		$userFieldManager = $this->getUserFieldManager();
		$attachmentIds = $this->normalizeAttachmentIds($attachmentIds);
		$this->preloadAttachments($userFieldManager, $attachmentIds);

		foreach ($attachmentIds as $attachmentId)
		{
			$attachment = $this->loadReadableMailTemplateAttachment(
				$userFieldManager,
				$attachmentId,
				$userId,
				$templateId,
			);
			if ($attachment === null)
			{
				continue;
			}

			$file = $this->getAttachedObjectFile($attachment);
			if ($file === null)
			{
				continue;
			}

			$objectId = (int)$file->getId();
			if (isset($filesByObjectId[$objectId]))
			{
				continue;
			}

			$filesByObjectId[$objectId] = $file;
			$attachmentCandidates[] = [
				'attachmentId' => $attachmentId,
				'attachment' => $attachment,
				'file' => $file,
				'objectId' => $objectId,
			];
		}

		$attachments = [];
		$securityContexts = $this->preloadFileReadOperations($filesByObjectId, $userId);
		foreach ($attachmentCandidates as $candidate)
		{
			$file = $candidate['file'];
			$objectId = $candidate['objectId'];
			if (!$this->canReadFile($file, $userId, $securityContexts[$objectId] ?? null))
			{
				continue;
			}

			$bodyHtml = $this->replaceAttachedObjectLink(
				$bodyHtml,
				$candidate['attachmentId'],
				$objectId,
			);
			$attachments[] = [
				'id' => $objectId,
				'name' => $file->getName(),
				'size' => $file->getSize(),
				'contentType' => $this->getContentType($candidate['attachment'], $file),
			];
		}

		return [$bodyHtml, $attachments];
	}

	private function getUserFieldManager(): object
	{
		$factory = $this->userFieldManagerFactory
			?? static fn(): object => Driver::getInstance()->getUserFieldManager()
		;

		return $factory();
	}

	/**
	 * @param list<int> $attachmentIds
	 * @return list<int>
	 */
	private function normalizeAttachmentIds(array $attachmentIds): array
	{
		$normalizedIds = [];
		$seenIds = [];
		foreach ($attachmentIds as $attachmentId)
		{
			$attachmentId = (int)$attachmentId;
			if ($attachmentId > 0 && !isset($seenIds[$attachmentId]))
			{
				$normalizedIds[] = $attachmentId;
				$seenIds[$attachmentId] = true;
			}
		}

		return $normalizedIds;
	}

	/** @param list<int> $attachmentIds */
	private function preloadAttachments(mixed $userFieldManager, array $attachmentIds): void
	{
		if (!method_exists($userFieldManager, 'loadBatchAttachedObject'))
		{
			return;
		}

		foreach (array_chunk($attachmentIds, self::ATTACHMENT_BATCH_SIZE) as $batch)
		{
			try
			{
				$userFieldManager->loadBatchAttachedObject($batch);
			}
			catch (\Throwable)
			{
				continue;
			}
		}
	}

	private function loadReadableMailTemplateAttachment(
		mixed $userFieldManager,
		int $attachmentId,
		int $userId,
		int $templateId,
	): ?AttachedObject
	{
		try
		{
			try
			{
				$attachment = $userFieldManager->getAttachedObjectById($attachmentId);
			}
			catch (\Throwable)
			{
				$attachment = AttachedObject::loadById($attachmentId);
			}

			if (!$attachment instanceof AttachedObject)
			{
				return null;
			}

			if (
				$attachment->getModuleId() !== 'crm'
				|| $attachment->getEntityType() !== MailTemplateConnector::class
				|| (int)$attachment->getEntityId() !== $templateId
			)
			{
				return null;
			}

			return $attachment->canRead($userId) ? $attachment : null;
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function getAttachedObjectFile(AttachedObject $attachment): ?File
	{
		try
		{
			$object = $attachment->getObject();
			if (!$object instanceof File || $object->isDeleted())
			{
				return null;
			}

			return $object;
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * @param array<int, File> $filesByObjectId
	 * @return array<int, SecurityContext>
	 */
	private function preloadFileReadOperations(array $filesByObjectId, int $userId): array
	{
		$groups = [];
		foreach ($filesByObjectId as $objectId => $file)
		{
			try
			{
				$storageId = (int)$file->getStorageId();
				$parentId = (int)$file->getParentId();
				if ($storageId <= 0 || $parentId <= 0)
				{
					continue;
				}

				$groupKey = $storageId . ':' . $parentId;
				$groups[$groupKey] ??= [
					'file' => $file,
					'objectIds' => [],
				];
				$groups[$groupKey]['objectIds'][] = $objectId;
			}
			catch (\Throwable)
			{
				continue;
			}
		}

		$securityContexts = [];
		foreach ($groups as $group)
		{
			try
			{
				$storage = $group['file']->getStorage();
				$parent = $group['file']->getParent();
				if ($storage === null || !$parent instanceof Folder)
				{
					continue;
				}

				$securityContext = $storage->getSecurityContext($userId);
				$parent->preloadOperationsForSpecifiedObjects($group['objectIds'], $securityContext);
				foreach ($group['objectIds'] as $objectId)
				{
					$securityContexts[$objectId] = $securityContext;
				}
			}
			catch (\Throwable)
			{
				continue;
			}
		}

		return $securityContexts;
	}

	private function canReadFile(
		File $file,
		int $userId,
		?SecurityContext $securityContext = null,
	): bool
	{
		try
		{
			if ($securityContext instanceof SecurityContext)
			{
				return $file->canRead($securityContext);
			}

			$storage = $file->getStorage();
			if ($storage === null)
			{
				return false;
			}

			return $file->canRead($storage->getSecurityContext($userId));
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function replaceAttachedObjectLink(string $bodyHtml, int $attachmentId, int $objectId): string
	{
		$pattern = '/bxacid:' . preg_quote((string)$attachmentId, '/') . '(?![0-9])/';

		return preg_replace($pattern, 'bxacid:n' . $objectId, $bodyHtml) ?? $bodyHtml;
	}

	private function getContentType(
		AttachedObject $attachment,
		File $file,
	): ?string
	{
		[$batchLoaded, $batchContentType] = $this->getBatchContentType($attachment);
		if ($batchLoaded)
		{
			return $batchContentType;
		}

		try
		{
			$fileData = $file->getFile();
		}
		catch (\Throwable)
		{
			return null;
		}

		if (!is_array($fileData))
		{
			return null;
		}

		$contentType = (string)($fileData['CONTENT_TYPE'] ?? '');

		return $contentType !== '' ? $contentType : null;
	}

	/** @return array{0: bool, 1: string|null} */
	private function getBatchContentType(AttachedObject $attachment): array
	{
		try
		{
			$extra = $attachment->getExtra();
			if ($extra->get('FILE_SIZE') === null)
			{
				return [false, null];
			}

			$contentType = (string)$extra->get('FILE_CONTENT_TYPE');

			return [true, $contentType !== '' ? $contentType : null];
		}
		catch (\Throwable)
		{
			return [false, null];
		}
	}
}
