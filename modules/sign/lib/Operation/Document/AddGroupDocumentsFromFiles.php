<?php

namespace Bitrix\Sign\Operation\Document;

use Bitrix\Main;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Integration\Bitrix24\B2eTariff;
use Bitrix\Sign\Item;
use Bitrix\Sign\Operation\Result\AddGroupDocumentsResult;
use Bitrix\Sign\Repository\Document\GroupRepository;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Result\Service\Sign\Document\CreateGroupResult;
use Bitrix\Sign\Service\B2e\B2eTariffRestrictionService;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\BlankService;
use Bitrix\Sign\Service\Sign\Document\GroupService;
use Bitrix\Sign\Service\Sign\DocumentAgentService;
use Bitrix\Sign\Service\Sign\DocumentService;
use Bitrix\Sign\Type;
use Bitrix\Sign\Type\Document\InitiatedByType;

/**
 * Creates one document per uploaded file and attaches every document to a documents group.
 *
 * Sending the blanks to the signing service is not part of this operation: one background agent per
 * group does it, so a batch never holds a php-fpm worker while the service works.
 *
 * Order of the incoming file list is the order of documents in the group.
 * A failure of a single file neither stops the batch nor rolls back already created documents;
 * the group limit is the only failure that stops processing of the remaining files.
 */
final class AddGroupDocumentsFromFiles implements Contract\Operation
{
	/**
	 * @param array<int, string> $fileIds Uploaded file ids in the order chosen by the user.
	 * @param bool $hasPlaceholders Whole batch trait: every blank of the batch is created with it.
	 */
	public function __construct(
		private readonly array $fileIds,
		private readonly int $createdById,
		private readonly int $groupId = 0,
		private readonly string $scenario = Type\BlankScenario::B2E,
		private readonly bool $hasPlaceholders = false,
		private readonly InitiatedByType $initiatedByType = InitiatedByType::COMPANY,
		private ?BlankService $blankService = null,
		private ?DocumentService $documentService = null,
		private ?GroupService $groupService = null,
		private ?GroupRepository $groupRepository = null,
		private ?B2eTariffRestrictionService $tariffRestrictionService = null,
		private ?Storage $storage = null,
		private ?DocumentRepository $documentRepository = null,
		private ?DocumentAgentService $documentAgentService = null,
	)
	{
		$container = Container::instance();
		$this->blankService ??= $container->getSignBlankService();
		$this->documentService ??= $container->getDocumentService();
		$this->groupService ??= $container->getDocumentGroupService();
		$this->groupRepository ??= $container->getDocumentGroupRepository();
		$this->tariffRestrictionService ??= $container->getB2eTariffRestrictionService();
		$this->storage ??= Storage::instance();
		$this->documentRepository ??= $container->getDocumentRepository();
		$this->documentAgentService ??= $container->getDocumentAgentService();
	}

	public function launch(): Main\Result
	{
		if (!Main\Loader::includeModule('ui'))
		{
			return (new AddGroupDocumentsResult())
				->addError(new Main\Error('Module ui is not installed', 'MODULE_UI_NOT_INSTALLED'))
			;
		}

		if (!$this->storage->isB2eAvailable())
		{
			return (new AddGroupDocumentsResult())
				->addError(new Main\Error('B2E is not available', 'B2E_NOT_AVAILABLE'))
			;
		}

		if ($this->fileIds === [])
		{
			return (new AddGroupDocumentsResult())
				->addError(new Main\Error('File list is empty', 'INVALID_FILE_LIST'))
			;
		}

		// The action behind the operation is B2E-only, so any other scenario is rejected
		// before a blank is created: the signing service would drop such a document anyway.
		if ($this->scenario !== Type\BlankScenario::B2E)
		{
			return (new AddGroupDocumentsResult())
				->addError(new Main\Error('Unsupported blank scenario', 'INVALID_SCENARIO'))
			;
		}

		if ($this->exceedsBatchTotalSize())
		{
			return (new AddGroupDocumentsResult())
				->addError(new Main\Error('Batch total size exceeded', 'BATCH_TOTAL_SIZE_EXCEEDED'))
			;
		}

		// The tariff restricts the whole batch, so it is checked once: per file it would repeat
		// a licence server request for every file of a batch that is rejected anyway.
		if ($this->isTariffRestricted())
		{
			return $this->rejectWholeBatch(AddGroupDocumentsResult::ERROR_TARIFF_RESTRICTED);
		}

		$groupResult = $this->resolveGroup();
		if (!$groupResult->isSuccess())
		{
			$result = new AddGroupDocumentsResult();

			return $result->addErrors($groupResult->getErrors());
		}

		$groupId = (int)$groupResult->getData()['groupId'];
		$capacityLeft = (int)$groupResult->getData()['capacityLeft'];
		$result = (new AddGroupDocumentsResult())->setGroupId($groupId);

		foreach ($this->fileIds as $fileId)
		{
			$fileId = (string)$fileId;
			// Files over the remaining capacity are rejected before anything is created for them.
			if ($capacityLeft < 1)
			{
				$result->addFailedItem($fileId, AddGroupDocumentsResult::ERROR_LIMIT_EXCEEDED);

				continue;
			}

			$errorCode = $this->addDocument($fileId, $groupId, $result);
			if ($errorCode === null)
			{
				$capacityLeft--;
			}
			elseif ($errorCode === AddGroupDocumentsResult::ERROR_LIMIT_EXCEEDED)
			{
				$capacityLeft = 0;
			}
		}

		// Sending a blank to the signing service encodes the whole file and waits for the service, so
		// it does not belong in a request: a batch of thirty would hold one php-fpm worker for about a
		// minute and, on a small pool, starve the portal. The documents stay in `new` until the chain
		// agent of the group brings them to `uploaded`, and page generation starts from there.
		if ($result->getSuccessCount() > 0)
		{
			$this->documentAgentService->addUploadGroupBlanksAgent($groupId);
		}

		return $result;
	}

	/**
	 * Rejects every file of the batch with the same code, without creating anything.
	 */
	private function rejectWholeBatch(string $errorCode): AddGroupDocumentsResult
	{
		$result = (new AddGroupDocumentsResult())->setGroupId($this->groupId);
		foreach ($this->fileIds as $fileId)
		{
			$result->addFailedItem((string)$fileId, $errorCode);
		}

		return $result;
	}

	private function exceedsBatchTotalSize(): bool
	{
		$maxTotalSize = $this->storage->getUploadBatchTotalMaxSize();
		if ($maxTotalSize < 1)
		{
			return false;
		}

		return $this->blankService->getUploadedFilesTotalSize($this->fileIds) > $maxTotalSize;
	}

	/**
	 * Returns the group of the batch and how many documents it can still take.
	 */
	private function resolveGroup(): Main\Result
	{
		$result = new Main\Result();
		if ($this->groupId > 0)
		{
			$group = $this->groupRepository->getById($this->groupId);
			// A group of another user answers the same way as a missing one:
			// a separate code would tell whether a foreign group exists.
			if ($group === null || $group->createdById !== $this->createdById)
			{
				return $result->addError(new Main\Error('Group not found', 'GROUP_NOT_FOUND'));
			}

			$documentCount = $this->documentRepository->getCountByGroupId($this->groupId);

			return $result->setData([
				'groupId' => $this->groupId,
				'capacityLeft' => max(GroupService::MAX_DOCUMENT_COUNT - $documentCount, 0),
			]);
		}

		$createResult = $this->groupService->create($this->createdById);
		if (!$createResult instanceof CreateGroupResult || !$createResult->isSuccess())
		{
			$result->addError(new Main\Error('Can\'t create document group', 'GROUP_CREATE_FAILED'));

			return $result->addErrors($createResult->getErrors());
		}

		return $result->setData([
			'groupId' => (int)$createResult->group->id,
			'capacityLeft' => GroupService::MAX_DOCUMENT_COUNT,
		]);
	}

	/**
	 * Creates a document for a single file and returns an error code when the file was rejected.
	 */
	private function addDocument(string $fileId, int $groupId, AddGroupDocumentsResult $result): ?string
	{
		$blankResult = $this->blankService->createFromFileIds(
			[$fileId],
			$this->scenario,
			false,
			$this->hasPlaceholders,
		);
		$blankId = (int)$blankResult->getId();
		if (!$blankResult->isSuccess() || $blankId < 1)
		{
			$this->logFailure($fileId, AddGroupDocumentsResult::ERROR_BLANK_CREATE_FAILED, $blankResult);
			$result->addFailedItem($fileId, AddGroupDocumentsResult::ERROR_BLANK_CREATE_FAILED);

			return AddGroupDocumentsResult::ERROR_BLANK_CREATE_FAILED;
		}

		$registerResult = $this->documentService->register(
			blankId: $blankId,
			createdById: $this->createdById,
			entityType: Type\Document\EntityType::getByScenarioType(Type\DocumentScenario::SCENARIO_TYPE_B2E),
			initiatedByType: $this->initiatedByType,
		);
		$document = $registerResult->getData()['document'] ?? null;
		if (!$registerResult->isSuccess() || !$document instanceof Item\Document || $document->uid === null)
		{
			$this->rollbackRegisteredDocument($registerResult);
			$this->blankService->rollbackById($blankId);
			$this->logFailure($fileId, AddGroupDocumentsResult::ERROR_DOCUMENT_REGISTER_FAILED, $registerResult);
			$result->addFailedItem($fileId, AddGroupDocumentsResult::ERROR_DOCUMENT_REGISTER_FAILED);

			return AddGroupDocumentsResult::ERROR_DOCUMENT_REGISTER_FAILED;
		}

		$attachResult = $this->groupService->attach($document->uid, $groupId);
		if (!$attachResult->isSuccess())
		{
			$errorCode = $this->getFirstErrorCode($attachResult) ?? AddGroupDocumentsResult::ERROR_ATTACH_FAILED;
			$this->documentService->rollbackDocument((int)$document->id);
			$this->blankService->rollbackById($blankId);
			$this->logFailure($fileId, $errorCode, $attachResult);
			$result->addFailedItem($fileId, $errorCode);

			return $errorCode;
		}

		$result->addSuccessItem($fileId, $document->uid, (int)$document->id, $blankId, $this->hasPlaceholders);

		return null;
	}

	private function isTariffRestricted(): bool
	{
		if (!$this->tariffRestrictionService->check()->isSuccess())
		{
			return true;
		}

		return B2eTariff::instance()->isB2eRestrictedInCurrentTariff();
	}

	private function rollbackRegisteredDocument(Main\Result $registerResult): void
	{
		$documentId = (int)($registerResult->getData()['documentId'] ?? 0);
		if ($documentId > 0)
		{
			$this->documentService->rollbackDocument($documentId);
		}
	}

	private function getFirstErrorCode(Main\Result $result): ?string
	{
		$code = ($result->getErrors()[0] ?? null)?->getCode();

		return is_string($code) && $code !== '' ? $code : null;
	}

	private function logFailure(string $fileId, string $errorCode, Main\Result $result): void
	{
		Container::instance()->getLogger('Operation')->warning(
			'SIGN: batch document add rejected file {fileId} with code {errorCode}: {errors}',
			[
				'fileId' => $fileId,
				'errorCode' => $errorCode,
				'errors' => implode('; ', $result->getErrorMessages()),
			],
		);
	}
}
