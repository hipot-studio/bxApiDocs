<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\Rag\Service;

use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\Internal\Integration\Rag\Result\KnowledgeBaseCreateResult;
use Bitrix\Bizproc\Internal\Integration\Rag\Result\KnowledgeBaseGetInfoResult;
use Bitrix\Bizproc\Internal\Integration\Rag\Result\KnowledgeBaseInfoResult;
use Bitrix\Bizproc\Internal\Integration\UI\UploaderHelper;
use Bitrix\Bizproc\Result;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Rag\Public\Dto\CreateKnowledgeBaseDto;
use Bitrix\Rag\Public\Dto\UpdateKnowledgeBaseDto;
use Bitrix\Rag\Public\Service\KnowledgeBasePublicService;

class KnowledgeBaseService
{
	private readonly KnowledgeBasePublicService $ragModuleBaseService;

	public function __construct(
		protected ValidationService $validationService,
		protected RagService $ragService,
		protected KnowledgeBaseFileService $fileService,
	)
	{
		if ($this->ragService->isAvailable())
		{
			$this->ragModuleBaseService = ServiceLocator::getInstance()->get(KnowledgeBasePublicService::class);
		}
	}

	private function create(string $name, string $description, int $userId): \Bitrix\Main\Result|KnowledgeBaseCreateResult
	{
		if (!$this->ragService->isAvailable())
		{
			return $this->ragService->createErrorModuleResult();
		}

		$createDto = new CreateKnowledgeBaseDto(
			description: $description,
			name: $name,
			userId: $userId,
		);

		$result = $this->validationService->validate($createDto);
		if (!$result->isSuccess())
		{
			return $result;
		}

		try
		{
			$idDto = $this->ragModuleBaseService->createBase($createDto);

			return new KnowledgeBaseCreateResult(
				id: $idDto->id,
				uuid: $idDto->uuid,
			);
		}
		catch (SystemException $exception)
		{
			$message = Loc::getMessage('BIZPROC_INTERNAL_INTEGRATION_RAG_KNOWLEDGE_BASE_SERVICE_CREATE_ERROR');

			return Result::createError(new Error($message, $exception->getCode()));
		}
	}

	public function getInfo(string $uid): \Bitrix\Main\Result|KnowledgeBaseGetInfoResult
	{
		if (!$this->ragService->isAvailable())
		{
			return $this->ragService->createErrorModuleResult();
		}

		try
		{
			$info = $this->ragModuleBaseService->getBaseInfoByUuid($uid);

			return new KnowledgeBaseGetInfoResult($info);
		}
		catch (SystemException $exception)
		{
			$message = Loc::getMessage('BIZPROC_INTERNAL_INTEGRATION_RAG_KNOWLEDGE_BASE_SERVICE_GET_ERROR');

			return Result::createError(new Error($message, $exception->getCode()));
		}
	}

	public function createWithFiles(
		string $name,
		string $description,
		array $fileIds,
		int $userId,
	): \Bitrix\Main\Result|KnowledgeBaseInfoResult
	{
		if (!$this->ragService->isAvailable())
		{
			return $this->ragService->createErrorModuleResult();
		}

		$result = $this->fileService->validateFilesCount(count($fileIds));
		if (!$result->isSuccess())
		{
			return $result;
		}

		[$tempFileIds] = UploaderHelper::splitFiles($fileIds);
		if (count($tempFileIds) !== count($fileIds))
		{
			$message = Loc::getMessage('BIZPROC_INTERNAL_INTEGRATION_RAG_KNOWLEDGE_BASE_SERVICE_INCORRECT_FILE_IDS');

			return $this->createErrorResultByMessage($message);
		}

		$pendingFiles = $this->fileService->getPendingFiles($tempFileIds);
		$result = $this->fileService->validatePendingFiles($pendingFiles, $tempFileIds);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result = $this->create(
			name: $name,
			description: $description,
			userId: $userId
		);
		if (!$result instanceof KnowledgeBaseCreateResult)
		{
			return $result;
		}

		$id = $result->id;
		$uid = $result->uuid;
		$fileIdReplaces = $this->fileService->getFileIdReplaceMap($pendingFiles);

		$fileSaveResult = $this->fileService->savePendingFiles($id, $userId, $pendingFiles);
		if (!$fileSaveResult->isSuccess())
		{
			$this->delete($id);

			return $fileSaveResult;
		}

		return new KnowledgeBaseInfoResult(
			uid: $uid,
			fileIds: $pendingFiles->getFileIds(),
			fileIdsReplaces: $fileIdReplaces,
		);
	}

	public function updateWithFiles(
		string $uid,
		string $name,
		string $description,
		array $fileIds,
		int $userId,
	): \Bitrix\Main\Result|KnowledgeBaseInfoResult
	{
		if (!$this->ragService->isAvailable())
		{
			return $this->ragService->createErrorModuleResult();
		}

		$result = $this->fileService->validateFilesCount(count($fileIds));
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result = $this->getInfo($uid);
		if (!$result instanceof KnowledgeBaseGetInfoResult)
		{
			return $result;
		}

		$info = $result->info;
		$id = $info->id;

		[$tempFileIds, $persistentFileIds] = UploaderHelper::splitFiles($fileIds);
		$unknownFiles = array_diff($persistentFileIds, $info->fileIds);
		if (count($unknownFiles) > 0)
		{
			$message = Loc::getMessage('BIZPROC_INTERNAL_INTEGRATION_RAG_KNOWLEDGE_BASE_SERVICE_UNKNOWN_FILE_IDS', [
				'#FILE_IDS#' => implode(', ', $unknownFiles),
			]);

			return $this->createErrorResultByMessage($message);
		}

		$pendingFiles = $this->fileService->getPendingFiles($tempFileIds, $uid);
		$result = $this->fileService->validatePendingFiles($pendingFiles, $tempFileIds);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result = $this->update(
			id: $id,
			name: $name,
			description: $description,
			userId: $userId,
		);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$filesToDelete = array_diff($info->fileIds, $persistentFileIds);
		$result = $this->fileService->deleteMany($id, $userId, $filesToDelete);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$fileIdReplaces = $this->fileService->getFileIdReplaceMap($pendingFiles);
		$result = $this->fileService->savePendingFiles($id, $userId, $pendingFiles);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return new KnowledgeBaseInfoResult(
			uid: $uid,
			fileIds: array_merge($persistentFileIds, $pendingFiles->getFileIds()),
			fileIdsReplaces: $fileIdReplaces,
		);
	}

	private function createErrorResultByMessage(?string $message): Result
	{
		return Result::createError(new Error($message));
	}

	private function update(
		int $id,
		string $name,
		string $description,
		int $userId,
	): \Bitrix\Main\Result
	{
		$updateDto = new UpdateKnowledgeBaseDto(
			idInDB: $id,
			description: $description,
			name: $name,
			userId: $userId,
		);
		$result = $this->validationService->validate($updateDto);
		if (!$result->isSuccess())
		{
			return $result;
		}

		try
		{
			$this->ragModuleBaseService->updateBase($updateDto);

			return new Result();
		}
		catch (SystemException $exception)
		{
			$message = Loc::getMessage('BIZPROC_INTERNAL_INTEGRATION_RAG_KNOWLEDGE_BASE_SERVICE_UPDATE_ERROR');

			return Result::createError(new Error($message, $exception->getCode()));
		}
	}

	private function delete(int $id): Result
	{
		try
		{
			$this->ragModuleBaseService->deleteBase($id);

			return new Result();
		}
		catch (SystemException $exception)
		{
			$message = Loc::getMessage('BIZPROC_INTERNAL_INTEGRATION_RAG_KNOWLEDGE_BASE_SERVICE_DELETE_ERROR');

			return Result::createError(new Error($message, $exception->getCode()));
		}
	}
}