<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\Rag\Service;

use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FileUploader\KnowledgeBaseUploaderController;
use Bitrix\Bizproc\Internal\Integration\Rag\Dto\KnowledgeBaseFileStatusDtoCollection;
use Bitrix\Bizproc\Internal\Integration\Rag\Dto\KnowledgeBaseFileStatusDto;
use Bitrix\Bizproc\Internal\Integration\Rag\FileStatus;
use Bitrix\Bizproc\Internal\Integration\UI\UploaderHelper;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Rag\Public\Enum\FileState;
use Bitrix\Rag\Public\Service\FileKnowledgeBasePublicService;
use Bitrix\UI\FileUploader\PendingFileCollection;
use Bitrix\UI\FileUploader\Uploader;

class KnowledgeBaseFileService
{
	private readonly FileKnowledgeBasePublicService $ragModuleFileService;

	public function __construct(
		protected RagService $ragService,
		protected KnowledgeBaseFileCacheService $cacheService,
	)
	{
		if ($this->ragService->isAvailable())
		{
			$this->ragModuleFileService = ServiceLocator::getInstance()->get(FileKnowledgeBasePublicService::class);
		}
	}

	public function validatePendingFiles(PendingFileCollection $pendingFiles, array $tempFileIds): Result
	{
		return UploaderHelper::validatePendingFiles($pendingFiles, $tempFileIds);
	}

	public function deleteMany(
		int $knowledgeBaseId,
		int $userId,
		array $fileIds,
		string $uid,
	): Result
	{
		if (!$this->ragService->isAvailable())
		{
			return $this->ragService->createErrorModuleResult();
		}

		if (empty($fileIds))
		{
			return new Result();
		}

		$this->cacheService->cleanCacheInfoUploadFiles($uid);

		try
		{
			$this->ragModuleFileService->deleteFiles($knowledgeBaseId, $fileIds, $userId);
			foreach ($fileIds as $fileId)
			{
				\CFile::Delete($fileId);
			}

			return new Result();
		}
		catch (SystemException $exception)
		{
			$message = Loc::getMessage('BIZPROC_INTERNAL_INTEGRATION_RAG_KNOWLEDGE_BASE_FILE_SERVICE_DELETE_ERROR');

			return \Bitrix\Bizproc\Result::createError(new Error($message, $exception->getCode()));
		}
	}

	private function addMany(int $knowledgeBaseId, array $fileIds, int $userId): Result
	{
		try
		{
			$this->ragModuleFileService->addFiles($knowledgeBaseId, $fileIds, $userId);

			return new Result();
		}
		catch (SystemException $exception)
		{
			$message = Loc::getMessage('BIZPROC_INTERNAL_INTEGRATION_RAG_KNOWLEDGE_BASE_FILE_SERVICE_ADD_ERROR');

			return \Bitrix\Bizproc\Result::createError(new Error($message, $exception->getCode()));
		}
	}

	private function createErrorResultByMessage(?string $message): Result
	{
		return (new Result())
			->addError(new Error($message))
		;
	}

	public function getPendingFiles(array $tempFileIds, string $uid = ''): PendingFileCollection
	{
		$uploadController = new KnowledgeBaseUploaderController([
			KnowledgeBaseUploaderController::OPTION_KNOWLEDGE_BASE_UID => $uid,
		]);
		$uploader = new Uploader($uploadController);

		return $uploader->getPendingFiles($tempFileIds);
	}

	public function validateFilesCount(int $filesCount): Result
	{
		if ($filesCount === 0)
		{
			$message = Loc::getMessage('BIZPROC_INTERNAL_INTEGRATION_RAG_KNOWLEDGE_BASE_FILE_SERVICE_NO_FILES_ERROR');

			return $this->createErrorResultByMessage($message);
		}

		if ($filesCount > $this->ragService->getMaxFilesCount())
		{
			$message = Loc::getMessage('BIZPROC_INTERNAL_INTEGRATION_RAG_KNOWLEDGE_BASE_FILE_SERVICE_TOO_MANY_FILES_ERROR');

			return $this->createErrorResultByMessage($message);
		}

		return new Result();
	}

	public function savePendingFiles(int $id, int $userId, PendingFileCollection $pendingFiles, string $uid): Result
	{
		if (!$this->ragService->isAvailable())
		{
			return $this->ragService->createErrorModuleResult();
		}
		
		$fileIds = $pendingFiles->getFileIds();
		if (empty($fileIds))
		{
			return new Result();
		}

		$this->cacheService->cleanCacheInfoUploadFiles($uid);

		$result = $this->addMany($id, $pendingFiles->getFileIds(), $userId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$pendingFiles->makePersistent();

		return new Result();
	}

	/**
	 * @param PendingFileCollection $pendingFiles
	 *
	 * @return array<string, int>
	 */
	public function getFileIdReplaceMap(PendingFileCollection $pendingFiles): array
	{
		$map = [];
		foreach ($pendingFiles as $pendingFile)
		{
			$map[$pendingFile->getId()] = $pendingFile->getFileId();
		}

		return $map;
	}

	public function getInfoUploadFiles(int $knowledgeBaseId): KnowledgeBaseFileStatusDtoCollection
	{
		$collection = new KnowledgeBaseFileStatusDtoCollection();

		if (!$this->ragService->isAvailable())
		{
			return $collection;
		}

		try {
			$files = $this->ragModuleFileService->getFilesForBaseWithState($knowledgeBaseId);
		}
		catch (SystemException)
		{
			return $collection;
		}

		foreach ($files as $fileId => $state)
		{
			$fileStatus = match ($state)
			{
				FileState::New,
				FileState::Ingesting,
				FileState::ReadyForUpload,
				FileState::Uploading => FileStatus::Uploading,
				FileState::ReadyForVectorize,
				FileState::Vectorizing => FileStatus::Processing,
				FileState::Active => FileStatus::Success,
				FileState::Failed,
				FileState::Deleted => FileStatus::FailedUpload,
				default => FileStatus::FailedUpload,
			};

			$collection->add(new KnowledgeBaseFileStatusDto(
				fileId: $fileId,
				status: $fileStatus,
			));
		}

		return $collection;
	}
}