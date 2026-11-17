<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Disk;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Storage;
use Bitrix\Mail\Integration\Disk\Dto\LargeAttachmentResult;
use Bitrix\Mail\Integration\Disk\Dto\LargeAttachmentTokenPayload;
use Bitrix\Main\Application;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Web\Json;
use Psr\Log\LoggerInterface;

final class RealLargeAttachmentStorage implements LargeAttachmentStorageInterface
{
	public const ERROR_INVALID_ARGUMENT = 'MAIL_LA_INVALID_ARGUMENT';
	public const ERROR_INVALID_TOKEN = 'MAIL_LA_INVALID_TOKEN';
	public const ERROR_ACCESS_DENIED = 'MAIL_LA_ACCESS_DENIED';
	public const ERROR_NO_SPACE = 'MAIL_LA_NO_SPACE';
	public const ERROR_UPLOAD_FAILED = 'MAIL_LA_UPLOAD_FAILED';
	public const ERROR_LINK_UNAVAILABLE = 'MAIL_LA_LINK_UNAVAILABLE';

	private const RESULT_KEY = 'result';
	private const DELETED_KEY = 'deleted';
	private const TOKEN_SALT = 'mail.large-attachment.v2';
	private const MAX_TOKEN_LENGTH = 8192;
	private const LOGGER_ID = 'mail.large_attachment.storage';

	private Signer $signer;
	private ?\Closure $uploadPossibilityChecker;
	private MailAttachmentLinkGateway $linkGateway;
	private ?LoggerInterface $logger = null;

	public function __construct(
		?Signer $signer = null,
		?callable $isUploadPossible = null,
		?MailAttachmentLinkGateway $linkGateway = null,
	)
	{
		$this->signer = $signer ?? new Signer();
		$this->uploadPossibilityChecker = $isUploadPossible !== null
			? $isUploadPossible(...)
			: null;
		$this->linkGateway = $linkGateway ?? new DiskMailAttachmentLinkGateway();
	}

	public function getMailAttachmentsFolder(int $userId): Result
	{
		if ($userId <= 0)
		{
			return $this->error('Invalid user id.', self::ERROR_INVALID_ARGUMENT);
		}

		if (!Loader::includeModule('disk'))
		{
			return $this->diskUnavailable();
		}

		try
		{
			[, $folder] = $this->getStorageAndMailFolder($userId);
		}
		catch (\Throwable)
		{
			return $this->diskUnavailable();
		}

		$result = new Result();
		$result->setData(['folderId' => (int)$folder->getId()]);

		return $result;
	}

	public function uploadAndLink(int $userId, array $diskFileIds): Result
	{
		$fileIds = LargeAttachmentTokenPayload::normalizeIds($diskFileIds);
		if ($userId <= 0 || $fileIds === null || $fileIds === [])
		{
			return $this->error('Invalid large attachment data.', self::ERROR_INVALID_ARGUMENT);
		}

		if (!Loader::includeModule('disk'))
		{
			return $this->diskUnavailable();
		}

		// asked before the set folder is created: a refusal must leave no object in the mail folder
		$availability = $this->checkLinkCreationAvailability();
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		$batchFolder = null;
		$createdLinkId = null;
		$linkFailure = null;

		try
		{
			[$storage, $mailFolder] = $this->getStorageAndMailFolder($userId);
			$securityContext = $storage->getSecurityContext($userId);
			if (!$mailFolder->canAdd($securityContext))
			{
				return $this->error('Access to the mail attachments folder is denied.', self::ERROR_ACCESS_DENIED);
			}

			$sourceFilesById = [];
			foreach (File::loadBatchById($fileIds) as $file)
			{
				if ($file instanceof File)
				{
					$sourceFilesById[(int)$file->getId()] = $file;
				}
			}

			$sourceFiles = [];
			foreach ($fileIds as $fileId)
			{
				$file = $sourceFilesById[$fileId] ?? null;
				if (!$file instanceof File || $file->isDeleted())
				{
					return $this->error('A source file is unavailable.', self::ERROR_INVALID_ARGUMENT);
				}

				$sourceStorage = $file->getStorage();
				if ($sourceStorage === null || !$file->canRead($sourceStorage->getSecurityContext($userId)))
				{
					return $this->error('Access to a source file is denied.', self::ERROR_ACCESS_DENIED);
				}

				$sourceFiles[] = $file;
			}

			$totalSize = array_sum(array_map(
				static fn(File $file): int => (int)$file->getSize(),
				$sourceFiles,
			));
			if (!$this->isUploadPossible($storage, $totalSize))
			{
				return $this->error(
					'There is not enough space in the Disk storage.',
					self::ERROR_NO_SPACE,
				);
			}

			$folderName = LargeAttachmentTokenPayload::FOLDER_NAME_PREFIX . Random::getString(32);
			$batchFolder = $mailFolder->addSubFolder(
				[
					'NAME' => $folderName,
					'CREATED_BY' => $userId,
				],
				[],
				true,
			);
			if (!$batchFolder instanceof Folder)
			{
				return $this->error('Could not create a large attachment folder.', self::ERROR_UPLOAD_FAILED);
			}

			$copiedFiles = [];
			foreach ($sourceFiles as $sourceFile)
			{
				$copy = $sourceFile->copyTo($batchFolder, $userId, true);
				if (!$copy instanceof File)
				{
					throw new \RuntimeException('Could not copy a source file.');
				}

				$copiedFiles[] = $copy;
			}

			$copiedObjectIds = array_map(
				static fn(File $file): int => (int)$file->getId(),
				$copiedFiles,
			);
			sort($copiedObjectIds, SORT_NUMERIC);

			$linkObject = count($copiedFiles) === 1 ? $copiedFiles[0] : $batchFolder;
			$created = $this->linkGateway->create($userId, (int)$linkObject->getId());
			if (!$created->isSuccess())
			{
				// a refusal may still name a link that was created: the cleanup below is the only place
				// that can delete it
				$createdLinkId = ((int)($created->getData()['externalLinkId'] ?? 0)) ?: null;

				// the refusal is already mapped to a code of the mail module and is carried to the catch
				// block, so that the copies are cleaned up in the single place that does the cleanup
				$linkFailure = $created;

				throw new \RuntimeException('Could not create a serviceable external link.');
			}

			$createdLink = $created->getData();
			$createdLinkId = (int)($createdLink['externalLinkId'] ?? 0);
			$externalLink = ExternalLink::loadById($createdLinkId);
			if (!$externalLink instanceof ExternalLink || !$this->isExpectedLink($externalLink, $userId, $linkObject))
			{
				throw new \RuntimeException('Could not read back a serviceable external link.');
			}

			$payload = new LargeAttachmentTokenPayload(
				$userId,
				(int)$storage->getId(),
				(int)$mailFolder->getId(),
				(int)$batchFolder->getId(),
				$batchFolder->getName(),
				(int)$linkObject->getId(),
				$createdLinkId,
				$externalLink->getHash(),
				$fileIds,
				$copiedObjectIds,
			);
			$token = $this->signPayload($payload);
			if (strlen($token) > self::MAX_TOKEN_LENGTH)
			{
				throw new \RuntimeException('Large attachment token is too long.');
			}

			$result = new Result();
			$result->setData([
				self::RESULT_KEY => new LargeAttachmentResult(
					(string)($createdLink['url'] ?? ''),
					$createdLinkId,
					$copiedObjectIds,
					$fileIds,
					$token,
				),
			]);

			return $result;
		}
		catch (\Throwable)
		{
			$this->rollback($batchFolder, $createdLinkId, $userId);

			return $linkFailure ?? $this->error(
				'Could not prepare a large attachment link.',
				self::ERROR_UPLOAD_FAILED,
			);
		}
	}

	public function extendAndLink(int $userId, string $token, array $diskFileIds): Result
	{
		$payload = $this->parsePayload($token);
		$fileIds = LargeAttachmentTokenPayload::normalizeIds($diskFileIds);
		if ($userId <= 0 || $payload === null || $fileIds === null || $fileIds === [])
		{
			return $this->error('Invalid large attachment data.', self::ERROR_INVALID_ARGUMENT);
		}

		if ($payload->userId !== $userId)
		{
			return $this->error('The large attachment token belongs to another user.', self::ERROR_ACCESS_DENIED);
		}

		$newFileIds = array_values(array_diff($fileIds, $payload->sourceFileIds));
		$isStrictExtension = count($fileIds) > count($payload->sourceFileIds)
			&& array_diff($payload->sourceFileIds, $fileIds) === [];
		if (!$isStrictExtension)
		{
			return $this->uploadAndLink($userId, $fileIds);
		}
		if (
			$newFileIds === []
		)
		{
			return $this->error('The replacement file set is invalid.', self::ERROR_INVALID_ARGUMENT);
		}

		if (!Loader::includeModule('disk'))
		{
			return $this->diskUnavailable();
		}

		// asked before the staging folder is created: a refusal must leave no object in the mail folder
		$availability = $this->checkLinkCreationAvailability();
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		$copiedFiles = [];
		$stagingFolder = null;
		$createdLinkId = null;
		$linkFailure = null;

		try
		{
			[$storage, $mailFolder, $folder, $externalLink] = $this->resolveObjects($payload, $userId);
			if (!$folder->canAdd($storage->getSecurityContext($userId)))
			{
				return $this->error(
					'Access to the large attachment folder is denied.',
					self::ERROR_ACCESS_DENIED,
				);
			}
			$sourceFilesById = [];
			foreach (File::loadBatchById($newFileIds) as $file)
			{
				if ($file instanceof File)
				{
					$sourceFilesById[(int)$file->getId()] = $file;
				}
			}

			$sourceFiles = [];
			foreach ($newFileIds as $fileId)
			{
				$file = $sourceFilesById[$fileId] ?? null;
				if (!$file instanceof File || $file->isDeleted())
				{
					return $this->error('A source file is unavailable.', self::ERROR_INVALID_ARGUMENT);
				}

				$sourceStorage = $file->getStorage();
				if ($sourceStorage === null || !$file->canRead($sourceStorage->getSecurityContext($userId)))
				{
					return $this->error('Access to a source file is denied.', self::ERROR_ACCESS_DENIED);
				}

				$sourceFiles[] = $file;
			}
			$totalSize = array_sum(array_map(
				static fn(File $file): int => (int)$file->getSize(),
				$sourceFiles,
			));
			if (!$this->isUploadPossible($storage, $totalSize))
			{
				return $this->error(
					'There is not enough space in the Disk storage.',
					self::ERROR_NO_SPACE,
				);
			}

			$stagingFolder = $mailFolder->addSubFolder(
				[
					'NAME' => LargeAttachmentTokenPayload::STAGING_FOLDER_NAME_PREFIX . Random::getString(32),
					'CREATED_BY' => $userId,
				],
				[],
				true,
			);
			if (!$stagingFolder instanceof Folder)
			{
				throw new \RuntimeException('Could not create a staging folder.');
			}

			foreach ($sourceFiles as $sourceFile)
			{
				$copy = $sourceFile->copyTo($stagingFolder, $userId, true);
				if (!$copy instanceof File)
				{
					throw new \RuntimeException('Could not copy a source file.');
				}

				$copiedFiles[] = $copy;
			}

			$stagedObjectIds = array_map(
				static fn(File $file): int => (int)$file->getId(),
				$copiedFiles,
			);
			sort($stagedObjectIds, SORT_NUMERIC);
			$copiedObjectIds = [
				...$payload->copiedObjectIds,
				...$stagedObjectIds,
			];
			sort($copiedObjectIds, SORT_NUMERIC);

			// a set of two or more files keeps its object, and then the idempotency of the command answers
			// with the link of the live set instead of creating a new one
			$created = $this->linkGateway->create($userId, (int)$folder->getId());
			if (!$created->isSuccess())
			{
				$createdLinkId = ((int)($created->getData()['externalLinkId'] ?? 0)) ?: null;
				$linkFailure = $created;

				throw new \RuntimeException('Could not create a serviceable external link.');
			}

			$createdLink = $created->getData();
			$createdLinkId = (int)($createdLink['externalLinkId'] ?? 0);
			$newExternalLink = ExternalLink::loadById($createdLinkId);
			if (
				!$newExternalLink instanceof ExternalLink
				|| !$this->isExpectedLink($newExternalLink, $userId, $folder)
			)
			{
				throw new \RuntimeException('Could not read back a serviceable external link.');
			}

			$result = $this->createUploadResult(
				$storage,
				$mailFolder,
				$folder,
				$newExternalLink,
				(string)($createdLink['url'] ?? ''),
				$fileIds,
				$copiedObjectIds,
				$stagingFolder,
				$stagedObjectIds,
				(int)$externalLink->getId(),
			);

			return $result;
		}
		catch (\Throwable)
		{
			// a link that came back from the idempotency of the command precedes this call and belongs to
			// the live set: only a link created by this very call may be deleted
			$this->rollback(
				$stagingFolder,
				$createdLinkId === $payload->externalLinkId ? null : $createdLinkId,
				$userId,
			);

			return $linkFailure ?? $this->error(
				'Could not extend a large attachment link.',
				self::ERROR_UPLOAD_FAILED,
			);
		}
	}

	public function finalizeReplacement(int $userId, string $previousToken, string $currentToken): Result
	{
		$previousPayload = $this->parsePayload($previousToken);
		$currentPayload = $this->parsePayload($currentToken);
		if ($userId <= 0 || $previousPayload === null || $currentPayload === null)
		{
			return $this->error('Invalid replacement token.', self::ERROR_INVALID_TOKEN);
		}

		if ($previousPayload->userId !== $userId || $currentPayload->userId !== $userId)
		{
			return $this->error('The replacement token belongs to another user.', self::ERROR_ACCESS_DENIED);
		}

		if (
			$previousPayload->storageId !== $currentPayload->storageId
			|| $previousPayload->sourceFileIds === $currentPayload->sourceFileIds
		)
		{
			return $this->error('The replacement tokens do not match.', self::ERROR_INVALID_TOKEN);
		}

		if (!Loader::includeModule('disk'))
		{
			return $this->diskUnavailable();
		}

		if (!$currentPayload->isPendingReplacement())
		{
			return $this->finalizeIndependentReplacement($userId, $previousPayload, $currentPayload);
		}

		if (
			$previousPayload->folderId !== $currentPayload->folderId
			|| $currentPayload->previousExternalLinkId !== $previousPayload->externalLinkId
		)
		{
			return $this->error('The replacement tokens do not match.', self::ERROR_INVALID_TOKEN);
		}

		$connection = Application::getConnection();
		$lockName = 'mail_large_attachment_' . $currentPayload->folderId;
		if (!$connection->lock($lockName, 5))
		{
			return $this->error(
				'Could not lock a large attachment replacement.',
				self::ERROR_UPLOAD_FAILED,
			);
		}

		$movedFiles = [];
		$stagingFolder = null;
		$isCommitted = false;
		try
		{
			[$storage, , $folder] = $this->resolveObjectsWithoutCopies($currentPayload, $userId);
			if (!$folder->canAdd($storage->getSecurityContext($userId)))
			{
				return $this->error(
					'Access to the large attachment folder is denied.',
					self::ERROR_ACCESS_DENIED,
				);
			}

			$stagingFolder = Folder::loadById($currentPayload->stagingFolderId);
			if (!$stagingFolder instanceof Folder)
			{
				$this->resolveObjects($currentPayload, $userId);

				return new Result();
			}
			$this->validateStagingFolder($stagingFolder, $currentPayload, $userId);

			$targetObjectIds = $this->getActualFileIds($folder, $currentPayload, $userId);
			$stagingObjectIds = $this->getActualFileIds($stagingFolder, $currentPayload, $userId);
			$actualObjectIds = [...$targetObjectIds, ...$stagingObjectIds];
			sort($actualObjectIds, SORT_NUMERIC);
			if ($actualObjectIds !== $currentPayload->copiedObjectIds)
			{
				throw new \RuntimeException('Replacement files do not match the token.');
			}

			$stagingObjectIdMap = array_fill_keys($stagingObjectIds, true);
			$stagedFilesById = [];
			foreach (File::loadBatchById($currentPayload->stagedObjectIds) as $stagedFile)
			{
				if ($stagedFile instanceof File)
				{
					$stagedFilesById[(int)$stagedFile->getId()] = $stagedFile;
				}
			}

			foreach ($currentPayload->stagedObjectIds as $stagedObjectId)
			{
				if (!isset($stagingObjectIdMap[$stagedObjectId]))
				{
					continue;
				}

				$file = $stagedFilesById[$stagedObjectId] ?? null;
				$movedFile = $file?->moveTo($folder, $userId, true);
				if (!$movedFile instanceof File)
				{
					throw new \RuntimeException('Could not commit a staged file.');
				}
				$movedFiles[] = $movedFile;
			}

			$this->resolveObjects($currentPayload, $userId);
			if ($previousPayload->externalLinkId !== $currentPayload->externalLinkId)
			{
				$previousLink = ExternalLink::loadById($previousPayload->externalLinkId);
				if ($previousLink !== null)
				{
					$this->validateLink($previousLink, $previousPayload, $userId);
					if (!$previousLink->delete())
					{
						throw new \RuntimeException('Could not delete the previous external link.');
					}
				}
			}
			$isCommitted = true;

			try
			{
				if (!$stagingFolder->deleteTree($userId))
				{
					throw new \RuntimeException('Could not delete the staging folder.');
				}
			}
			catch (\Throwable $exception)
			{
				$this->getLogger()->warning(
					'Failed to delete an empty large attachment staging folder.',
					[
						'resourceId' => (int)$stagingFolder->getId(),
						'userId' => $userId,
						'exception' => $exception,
					],
				);
			}

			return new Result();
		}
		catch (\Throwable $exception)
		{
			if (!$isCommitted && $stagingFolder instanceof Folder)
			{
				foreach (array_reverse($movedFiles) as $movedFile)
				{
					if (!$movedFile->moveTo($stagingFolder, $userId, true) instanceof File)
					{
						$this->getLogger()->error('Failed to roll back a staged large attachment file.', [
							'resourceId' => (int)$movedFile->getId(),
							'userId' => $userId,
						]);
					}
				}
			}
			$this->getLogger()->warning('Failed to finalize a large attachment replacement.', [
				'userId' => $userId,
				'exception' => $exception,
			]);

			return $this->error(
				'Could not finalize a large attachment replacement.',
				self::ERROR_UPLOAD_FAILED,
			);
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	public function resolveForSend(int $userId, string $token, array $diskFileIds): Result
	{
		$payload = $this->parsePayload($token);
		$fileIds = LargeAttachmentTokenPayload::normalizeIds($diskFileIds);
		if ($payload === null || $fileIds === null)
		{
			return $this->error('Invalid large attachment token.', self::ERROR_INVALID_TOKEN);
		}

		if ($userId <= 0 || $payload->userId !== $userId)
		{
			return $this->error('The large attachment token belongs to another user.', self::ERROR_ACCESS_DENIED);
		}

		if ($payload->sourceFileIds !== $fileIds)
		{
			return $this->error('The large attachment file set does not match the token.', self::ERROR_INVALID_TOKEN);
		}

		if (!Loader::includeModule('disk'))
		{
			return $this->diskUnavailable();
		}

		try
		{
			[, , $folder, $externalLink] = $this->resolveObjects($payload, $userId);

			$result = new Result();
			$result->setData([
				self::RESULT_KEY => new LargeAttachmentResult(
					$externalLink->generateUrl()->getUri(),
					(int)$externalLink->getId(),
					$payload->copiedObjectIds,
					$payload->sourceFileIds,
					$token,
				),
			]);

			return $result;
		}
		catch (\Throwable)
		{
			return $this->error('The large attachment link is unavailable.', self::ERROR_LINK_UNAVAILABLE);
		}
	}

	private function finalizeIndependentReplacement(
		int $userId,
		LargeAttachmentTokenPayload $previousPayload,
		LargeAttachmentTokenPayload $currentPayload,
	): Result
	{
		if ($previousPayload->folderId === $currentPayload->folderId)
		{
			return $this->error('The replacement tokens do not match.', self::ERROR_INVALID_TOKEN);
		}

		$connection = Application::getConnection();
		$lockName = 'mail_large_attachment_' . $previousPayload->folderId;
		if (!$connection->lock($lockName, 5))
		{
			return $this->error(
				'Could not lock a large attachment replacement.',
				self::ERROR_UPLOAD_FAILED,
			);
		}

		try
		{
			$this->resolveObjects($currentPayload, $userId);
			$previousFolder = Folder::loadById($previousPayload->folderId);
			if ($previousFolder instanceof Folder)
			{
				$this->validateFolder($previousFolder, $previousPayload, $userId);
				$this->validateCopies($previousFolder, $previousPayload, $userId);
				if (!$previousFolder->deleteTree($userId))
				{
					throw new \RuntimeException('Could not delete the previous attachment folder.');
				}
			}

			$previousLink = ExternalLink::loadById($previousPayload->externalLinkId);
			if ($previousLink instanceof ExternalLink)
			{
				try
				{
					$this->validateLink($previousLink, $previousPayload, $userId);
					if (!$previousLink->delete())
					{
						throw new \RuntimeException('Could not delete the previous external link.');
					}
				}
				catch (\Throwable $exception)
				{
					$this->getLogger()->warning('Failed to delete an obsolete large attachment link.', [
						'resourceId' => (int)$previousLink->getId(),
						'userId' => $userId,
						'exception' => $exception,
					]);
				}
			}

			return new Result();
		}
		catch (\Throwable $exception)
		{
			$this->getLogger()->warning('Failed to finalize an independent attachment replacement.', [
				'userId' => $userId,
				'exception' => $exception,
			]);

			return $this->error(
				'Could not finalize a large attachment replacement.',
				self::ERROR_UPLOAD_FAILED,
			);
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	public function deleteUploaded(int $userId, string $token): Result
	{
		$payload = $this->parsePayload($token);
		if ($payload === null)
		{
			return $this->error('Invalid large attachment token.', self::ERROR_INVALID_TOKEN);
		}

		if ($userId <= 0 || $payload->userId !== $userId)
		{
			return $this->error('The large attachment token belongs to another user.', self::ERROR_ACCESS_DENIED);
		}

		if (!Loader::includeModule('disk'))
		{
			return $this->diskUnavailable();
		}

		try
		{
			[$storage, $mailFolder] = $this->getStorageAndMailFolder($userId);
			if (
				(int)$storage->getId() !== $payload->storageId
				|| (int)$mailFolder->getId() !== $payload->mailFolderId
			)
			{
				throw new \RuntimeException('Storage hierarchy does not match the token.');
			}

			if ($payload->isPendingReplacement())
			{
				$stagingFolder = Folder::loadById($payload->stagingFolderId);
				if ($stagingFolder instanceof Folder)
				{
					$folder = Folder::loadById($payload->folderId);
					if (!$folder instanceof Folder)
					{
						throw new \RuntimeException('Large attachment folder is unavailable.');
					}
					$this->validateStagingFolder($stagingFolder, $payload, $userId);
					$isCommitted = $this->getActualFileIds($stagingFolder, $payload, $userId) === []
						&& $this->getActualFileIds($folder, $payload, $userId) === $payload->copiedObjectIds;
					if ($isCommitted)
					{
						$stagingFolder->deleteTree($userId);
					}
					else
					{
						$this->deletePendingReplacement($payload, $stagingFolder, $userId);

						return $this->deletedResult(true);
					}
				}
			}

			$folder = Folder::loadById($payload->folderId);
			$externalLink = ExternalLink::loadById($payload->externalLinkId);
			if ($folder === null && $externalLink === null)
			{
				return $this->deletedResult(false);
			}

			if ($folder !== null)
			{
				$this->validateFolder($folder, $payload, $userId);
				$this->validateCopies($folder, $payload, $userId);
			}

			if ($externalLink !== null)
			{
				$this->validateLink($externalLink, $payload, $userId);
			}

			$deleted = false;
			if ($externalLink !== null)
			{
				if (!$externalLink->delete())
				{
					throw new \RuntimeException('Could not delete the external link.');
				}
				$deleted = true;
			}

			if ($folder !== null)
			{
				if (!$folder->deleteTree($userId))
				{
					throw new \RuntimeException('Could not delete the large attachment folder.');
				}
				$deleted = true;
			}

			return $this->deletedResult($deleted);
		}
		catch (\Throwable)
		{
			return $this->error(
				'Could not safely delete the large attachment set.',
				self::ERROR_UPLOAD_FAILED,
			);
		}
	}

	/**
	 * @return array{0: Storage, 1: Folder}
	 */
	private function getStorageAndMailFolder(int $userId): array
	{
		$storage = Driver::getInstance()->getStorageByUserId($userId);
		if (!$storage instanceof Storage)
		{
			throw new \RuntimeException('User storage is unavailable.');
		}

		$folder = $storage->getFolderForMailAttachments();
		if (!$folder instanceof Folder)
		{
			throw new \RuntimeException('Mail attachments folder is unavailable.');
		}

		return [$storage, $folder];
	}

	/**
	 * @return array{0: Storage, 1: Folder, 2: Folder, 3: ExternalLink}
	 */
	private function resolveObjects(LargeAttachmentTokenPayload $payload, int $userId): array
	{
		[$storage, $mailFolder, $folder] = $this->resolveObjectsWithoutCopies($payload, $userId);
		$this->validateCopies($folder, $payload, $userId);

		$externalLink = ExternalLink::loadById($payload->externalLinkId);
		if (!$externalLink instanceof ExternalLink)
		{
			throw new \RuntimeException('External link is unavailable.');
		}
		$this->validateLink($externalLink, $payload, $userId);

		return [$storage, $mailFolder, $folder, $externalLink];
	}

	/**
	 * @return array{0: Storage, 1: Folder, 2: Folder}
	 */
	private function resolveObjectsWithoutCopies(LargeAttachmentTokenPayload $payload, int $userId): array
	{
		[$storage, $mailFolder] = $this->getStorageAndMailFolder($userId);
		if (
			(int)$storage->getId() !== $payload->storageId
			|| (int)$mailFolder->getId() !== $payload->mailFolderId
		)
		{
			throw new \RuntimeException('Storage hierarchy does not match the token.');
		}

		$folder = Folder::loadById($payload->folderId);
		if (!$folder instanceof Folder)
		{
			throw new \RuntimeException('Large attachment folder is unavailable.');
		}
		$this->validateFolder($folder, $payload, $userId);

		return [$storage, $mailFolder, $folder];
	}

	private function validateFolder(Folder $folder, LargeAttachmentTokenPayload $payload, int $userId): void
	{
		if (
			(int)$folder->getId() !== $payload->folderId
			|| (int)$folder->getStorageId() !== $payload->storageId
			|| (int)$folder->getParentId() !== $payload->mailFolderId
			|| (int)$folder->getCreatedBy() !== $userId
			|| $folder->getName() !== $payload->folderName
			|| !str_starts_with($folder->getName(), LargeAttachmentTokenPayload::FOLDER_NAME_PREFIX)
			|| $folder->isDeleted()
		)
		{
			throw new \RuntimeException('Large attachment folder does not match the token.');
		}
	}

	private function validateStagingFolder(Folder $folder, LargeAttachmentTokenPayload $payload, int $userId): void
	{
		if (
			(int)$folder->getId() !== $payload->stagingFolderId
			|| (int)$folder->getStorageId() !== $payload->storageId
			|| (int)$folder->getParentId() !== $payload->mailFolderId
			|| (int)$folder->getCreatedBy() !== $userId
			|| $folder->getName() !== $payload->stagingFolderName
			|| !str_starts_with($folder->getName(), LargeAttachmentTokenPayload::STAGING_FOLDER_NAME_PREFIX)
			|| $folder->isDeleted()
		)
		{
			throw new \RuntimeException('Large attachment staging folder does not match the token.');
		}
	}

	private function validateCopies(Folder $folder, LargeAttachmentTokenPayload $payload, int $userId): void
	{
		$actualObjectIds = $this->getActualFileIds($folder, $payload, $userId);

		if ($actualObjectIds !== $payload->copiedObjectIds)
		{
			throw new \RuntimeException('Large attachment folder contents do not match the token.');
		}
	}

	/**
	 * @return int[]
	 */
	private function getActualFileIds(Folder $folder, LargeAttachmentTokenPayload $payload, int $userId): array
	{
		$actualObjectIds = [];
		foreach (
			$folder->getChildren(
				Driver::getInstance()->getFakeSecurityContext(),
				['filter' => ['MIXED_SHOW_DELETED' => true]],
			)
			as $child
		)
		{
			if (
				!$child instanceof File
				|| (int)$child->getParentId() !== (int)$folder->getId()
				|| (int)$child->getStorageId() !== $payload->storageId
				|| (int)$child->getCreatedBy() !== $userId
				|| $child->isDeleted()
			)
			{
				throw new \RuntimeException('A copied file does not match the token.');
			}

			$actualObjectIds[] = (int)$child->getId();
		}
		sort($actualObjectIds, SORT_NUMERIC);

		return $actualObjectIds;
	}

	private function validateLink(
		ExternalLink $externalLink,
		LargeAttachmentTokenPayload $payload,
		int $userId,
	): void
	{
		if (
			(int)$externalLink->getId() !== $payload->externalLinkId
			|| !hash_equals($payload->externalLinkHash, $externalLink->getHash())
			|| !$this->hasExpectedLinkProperties($externalLink, $userId, $payload->linkObjectId)
		)
		{
			throw new \RuntimeException('External link does not match the token.');
		}
	}

	/**
	 * The check of a link this very call has just created, and it is stricter than the check of a link the
	 * token names: here we know which gateway made it, so a gateway that promises the mark of the service
	 * scenario has to be held to that promise.
	 */
	private function isExpectedLink(ExternalLink $externalLink, int $userId, BaseObject $object): bool
	{
		if (!$this->hasExpectedLinkProperties($externalLink, $userId, (int)$object->getId()))
		{
			return false;
		}

		return !$this->linkGateway->createsServiceLink() || $externalLink->isMailAttachmentLink();
	}

	/**
	 * The profile accepts a link of either way: the service link of the command carries the mail
	 * attachments scenario on top of these very fields, and a link of the previous way
	 * {@see LegacyMailAttachmentLinkGateway} carries the same fields and no scenario. Asking for the
	 * scenario would reject nothing but the sets created the previous way, which have to stay
	 * serviceable after the update of Disk arrives; it is not asked, so isMailAttachmentLink() is never
	 * called and an older Disk without that method is safe.
	 */
	private function hasExpectedLinkProperties(ExternalLink $externalLink, int $userId, int $objectId): bool
	{
		return (int)$externalLink->getObjectId() === $objectId
			&& (int)$externalLink->getCreatedBy() === $userId
			&& $externalLink->getType() === ExternalLink::TYPE_MANUAL
			&& $externalLink->getAccessRight() === ExternalLink::ACCESS_RIGHT_VIEW
			&& $externalLink->isCanDownloadWithReadAccess()
			&& !$externalLink->canEditSettings()
			&& !$externalLink->isExpired();
	}

	private function signPayload(LargeAttachmentTokenPayload $payload): string
	{
		$encoded = rtrim(strtr(base64_encode(Json::encode($payload->toArray())), '+/', '-_'), '=');

		return $this->signer->sign($encoded, self::TOKEN_SALT);
	}

	/**
	 * @param string $publicUrl address of the link as the command reported it, not as the model builds it
	 * @param int[] $sourceFileIds
	 * @param int[] $copiedObjectIds
	 */
	private function createUploadResult(
		Storage $storage,
		Folder $mailFolder,
		Folder $folder,
		ExternalLink $externalLink,
		string $publicUrl,
		array $sourceFileIds,
		array $copiedObjectIds,
		?Folder $stagingFolder = null,
		array $stagedObjectIds = [],
		int $previousExternalLinkId = 0,
	): Result
	{
		$payload = new LargeAttachmentTokenPayload(
			(int)$externalLink->getCreatedBy(),
			(int)$storage->getId(),
			(int)$mailFolder->getId(),
			(int)$folder->getId(),
			$folder->getName(),
			(int)$externalLink->getObjectId(),
			(int)$externalLink->getId(),
			$externalLink->getHash(),
			$sourceFileIds,
			$copiedObjectIds,
		);
		if ($stagingFolder instanceof Folder)
		{
			$payload = $payload->withPendingReplacement(
				(int)$stagingFolder->getId(),
				$stagingFolder->getName(),
				$stagedObjectIds,
				$previousExternalLinkId,
			);
		}
		$token = $this->signPayload($payload);
		if (strlen($token) > self::MAX_TOKEN_LENGTH)
		{
			throw new \RuntimeException('Large attachment token is too long.');
		}

		$result = new Result();
		$result->setData([
			self::RESULT_KEY => new LargeAttachmentResult(
				$publicUrl,
				(int)$externalLink->getId(),
				$copiedObjectIds,
				$sourceFileIds,
				$token,
			),
		]);

		return $result;
	}

	private function parsePayload(string $token): ?LargeAttachmentTokenPayload
	{
		if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH)
		{
			return null;
		}

		try
		{
			$encoded = $this->signer->unsign($token, self::TOKEN_SALT);
			$padding = strlen($encoded) % 4;
			if ($padding !== 0)
			{
				$encoded .= str_repeat('=', 4 - $padding);
			}

			$json = base64_decode(strtr($encoded, '-_', '+/'), true);
			if ($json === false)
			{
				return null;
			}

			$payload = Json::decode($json);
		}
		catch (\Throwable)
		{
			return null;
		}

		return LargeAttachmentTokenPayload::fromArray($payload);
	}

	private function deletePendingReplacement(
		LargeAttachmentTokenPayload $payload,
		Folder $stagingFolder,
		int $userId,
	): void
	{
		$folder = Folder::loadById($payload->folderId);
		if (!$folder instanceof Folder)
		{
			throw new \RuntimeException('Large attachment folder is unavailable.');
		}
		$this->validateFolder($folder, $payload, $userId);
		$this->validateStagingFolder($stagingFolder, $payload, $userId);

		$targetObjectIds = $this->getActualFileIds($folder, $payload, $userId);
		$expectedTargetObjectIds = array_values(array_diff(
			$payload->copiedObjectIds,
			$payload->stagedObjectIds,
		));
		sort($expectedTargetObjectIds, SORT_NUMERIC);
		if ($targetObjectIds !== $expectedTargetObjectIds)
		{
			throw new \RuntimeException('Large attachment folder contents do not match the pending token.');
		}

		$stagingObjectIds = $this->getActualFileIds($stagingFolder, $payload, $userId);
		if ($stagingObjectIds !== $payload->stagedObjectIds)
		{
			throw new \RuntimeException('Staging folder contents do not match the pending token.');
		}

		if ($payload->externalLinkId !== $payload->previousExternalLinkId)
		{
			$externalLink = ExternalLink::loadById($payload->externalLinkId);
			if ($externalLink instanceof ExternalLink)
			{
				$this->validateLink($externalLink, $payload, $userId);
				if (!$externalLink->delete())
				{
					throw new \RuntimeException('Could not delete the pending external link.');
				}
			}
		}

		if (!$stagingFolder->deleteTree($userId))
		{
			throw new \RuntimeException('Could not delete the staging folder.');
		}
	}

	/**
	 * Cleans up after a failed upload; the link is addressed by its id and not by its model, because the
	 * call may have failed exactly on reading the model of an already created link.
	 */
	private function rollback(?Folder $folder, ?int $createdLinkId, int $userId): void
	{
		$failures = [];

		if ($createdLinkId !== null)
		{
			try
			{
				$externalLink = ExternalLink::loadById($createdLinkId);
				if (!$externalLink instanceof ExternalLink || !$externalLink->delete())
				{
					$failures[] = [
						'resourceType' => 'externalLink',
						'resourceId' => $createdLinkId,
						'exception' => null,
					];
				}
			}
			catch (\Throwable $exception)
			{
				$failures[] = [
					'resourceType' => 'externalLink',
					'resourceId' => $createdLinkId,
					'exception' => $exception,
				];
			}
		}

		if ($folder !== null)
		{
			try
			{
				if (!$folder->deleteTree($userId))
				{
					$failures[] = [
						'resourceType' => 'folder',
						'resourceId' => (int)$folder->getId(),
						'exception' => null,
					];
				}
			}
			catch (\Throwable $exception)
			{
				$failures[] = [
					'resourceType' => 'folder',
					'resourceId' => (int)$folder->getId(),
					'exception' => $exception,
				];
			}
		}

		foreach ($failures as $failure)
		{
			$this->getLogger()->warning('Failed to roll back a large attachment resource.', [
				'resourceType' => $failure['resourceType'],
				'resourceId' => $failure['resourceId'],
				'userId' => $userId,
				'exception' => $failure['exception'],
			]);
		}
	}

	/**
	 * @param File[] $files
	 */
	private function rollbackAddedFiles(array $files, ?ExternalLink $externalLink, int $userId): void
	{
		if ($externalLink !== null)
		{
			try
			{
				if (!$externalLink->delete())
				{
					throw new \RuntimeException('Could not delete the external link.');
				}
			}
			catch (\Throwable $exception)
			{
				$this->getLogger()->warning('Failed to roll back a large attachment external link.', [
					'resourceId' => (int)$externalLink->getId(),
					'userId' => $userId,
					'exception' => $exception,
				]);
			}
		}

		foreach ($files as $file)
		{
			try
			{
				if (!$file->delete($userId))
				{
					throw new \RuntimeException('Could not delete the copied file.');
				}
			}
			catch (\Throwable $exception)
			{
				$this->getLogger()->warning('Failed to roll back a large attachment file.', [
					'resourceId' => (int)$file->getId(),
					'userId' => $userId,
					'exception' => $exception,
				]);
			}
		}
	}

	private function getLogger(): LoggerInterface
	{
		if ($this->logger === null)
		{
			$this->logger = (new LoggerFactory())->createById(
				self::LOGGER_ID,
				[],
				false,
			);
		}

		return $this->logger;
	}

	private function deletedResult(bool $deleted): Result
	{
		$result = new Result();
		$result->setData([self::DELETED_KEY => $deleted]);

		return $result;
	}

	/**
	 * Tells whether the service link may be created at all, translating the answer of the gateway into the
	 * refusal the caller gets. Successful result carries no data.
	 *
	 * The portal policy of manual links is deliberately not asked here, and this is not an omission of the
	 * mail module: whether it governs the link at all depends on the way the link is created, so it is the
	 * gateway that answers for it. A service link of a mail attachment is exempt by the policy of Disk
	 * itself, whose very first branch answers them, while a link of the previous way is a manual one and
	 * obeys the policy as any other.
	 *
	 * @see \Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkOpenPolicy::canOpenByPortalPolicy()
	 */
	private function checkLinkCreationAvailability(): Result
	{
		try
		{
			$availability = $this->linkGateway->canCreate();
		}
		catch (\Throwable $exception)
		{
			// the question is asked before the guarded block of the caller, so an unexpected throw would
			// reach the controller and answer the client with the message of the disk side
			$this->getLogger()->error('Asking for the availability of a large attachment link has thrown.', [
				'exception' => $exception,
			]);

			return $this->error(
				'Could not ask for the availability of a large attachment link.',
				self::ERROR_UPLOAD_FAILED,
			);
		}

		if (!$availability->isSuccess())
		{
			return $availability;
		}

		if (($availability->getData()['available'] ?? false) !== true)
		{
			return $this->error(
				'Creating a large attachment link is not available.',
				self::ERROR_DISK_FEATURE_UNAVAILABLE,
			);
		}

		return new Result();
	}

	private function isUploadPossible(Storage $storage, int $totalSize): bool
	{
		if ($this->uploadPossibilityChecker !== null)
		{
			return ($this->uploadPossibilityChecker)($storage, $totalSize);
		}

		return $storage->isPossibleToUpload($totalSize);
	}

	private function diskUnavailable(): Result
	{
		return $this->error(
			'Large attachment disk API is unavailable.',
			self::ERROR_DISK_UNAVAILABLE,
		);
	}

	private function error(string $message, string $code): Result
	{
		$result = new Result();

		return $result->addError(new Error($message, $code));
	}
}

