<?php

namespace Bitrix\Sign\Operation\Document;

use Bitrix\Main;
use Bitrix\Main\Error;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Item;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Helper\CloneHelper;
use Bitrix\Sign\Item\Document\Config\DocumentBlankReplacementConfig;
use Bitrix\Sign\Item\Document\BindingCollection;
use Bitrix\Sign\Repository\FileRepository;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Result\CreateDocumentResult;
use Bitrix\Sign\Result\Result;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\BlankService;
use Bitrix\Sign\Service\Sign\DocumentService;
use Bitrix\Sign\Service\Sign\MemberService;
use Bitrix\Sign\Type\BlankScenario;
use Bitrix\Sign\Type\DocumentScenario;

final class Copy implements Contract\Operation
{
	private readonly DocumentService $documentService;
	private readonly DocumentRepository $documentRepository;
	private readonly MemberRepository $memberRepository;
	private readonly MemberService $memberService;
	private readonly BlankService $blankService;
	private readonly FileRepository $fileRepository;

	public function __construct(
		private readonly Item\Document $document,
		private readonly int $createdByUserId,
		private readonly ?int $templateId = null,
		private readonly ?BindingCollection $bindings = null,
		private readonly bool $excludeRejected = true,
		private readonly ?DocumentBlankReplacementConfig $blankReplacementConfig = null,
		?DocumentService $documentService = null,
		?DocumentRepository $documentRepository = null,
		?MemberRepository $memberRepository = null,
		?MemberService $memberService = null,
		?BlankService $blankService = null,
		?FileRepository $fileRepository = null,
	)
	{
		$container = Container::instance();

		$this->documentService = $documentService ?? $container->getDocumentService();
		$this->documentRepository = $documentRepository ?? $container->getDocumentRepository();
		$this->memberRepository = $memberRepository ?? $container->getMemberRepository();
		$this->memberService = $memberService ?? $container->getMemberService();
		$this->blankService = $blankService ?? $container->getSignBlankService();
		$this->fileRepository = $fileRepository ?? $container->getFileRepository();
	}

	public function launch(): Main\Result|CreateDocumentResult
	{
		if ($this->document->id === null)
		{
			return Result::createByErrorData(message: 'Document is not saved');
		}

		if (
			$this->blankReplacementConfig !== null
			&& $this->blankReplacementConfig->blankReplacementFileId < 1
		)
		{
			return (new Result())->addError(new Error('File id is invalid'));
		}

		$result = $this->registerAndUploadDocument();
		if (!$result instanceof CreateDocumentResult)
		{
			return $result;
		}
		$newDocument = $result->document;

		$this->updateDocumentProperties($this->document, $newDocument);
		$result = $this->documentRepository->update($newDocument);
		if (!$result->isSuccess())
		{
			return $this->handleFailure($result, $newDocument);
		}

		if ($newDocument->representativeId === null)
		{
			$result = (new Result())->addError(new Error('Representative id is not set'));
			return $this->handleFailure($result, $newDocument);
		}

		$members = $this->memberRepository->listByDocumentId($this->document->id);
		$result = $this->memberService->setupB2eMembers(
			$newDocument->uid,
			$members,
			$newDocument->representativeId,
			excludeRejected: $this->excludeRejected,
		);
		if (!$result->isSuccess())
		{
			return $this->handleFailure($result, $newDocument);
		}

		return new CreateDocumentResult($newDocument);
	}

	public function updateDocumentProperties(Document $oldDocument, Document $newDocument): void
	{
		$newDocument->createdFromDocumentId = $this->document->id;
		$newDocument->templateId = null;
		$newDocument->createdById = $this->createdByUserId;
		$newDocument->stoppedById = null;
		CloneHelper::copyPropertiesIfPossible($oldDocument, $newDocument);
	}

	private function registerAndUploadDocument(): CreateDocumentResult|Main\Result
	{
		$result = $this->documentService->register(
			blankId: $this->document->blankId,
			createdById: $this->createdByUserId,
			title: $this->document->title,
			entityType: $this->document->entityType,
			asTemplate: false,
			initiatedByType: $this->document->initiatedByType,
			templateId: $this->templateId,
			bindings: $this->bindings,
		);
		if (!$result->isSuccess())
		{
			return $result;
		}
		$newDocument = $result->getData()['document'] ?? null;
		if (!$newDocument instanceof Document)
		{
			return Result::createByErrorData(message: 'Cant create new document by template');
		}

		if ($this->blankReplacementConfig !== null)
		{
			$replaceResult = $this->replaceDocumentFile(
				document: $newDocument,
				replacementConfig: $this->blankReplacementConfig,
			);
			if (!$replaceResult->isSuccess())
			{
				return $this->handleFailure($replaceResult, $newDocument);
			}
		}

		$result = $this->documentService->upload($newDocument->uid);
		if (!$result->isSuccess())
		{
			return $this->handleFailure($result, $newDocument);
		}
		$newDocument = $result->getData()['document'] ?? null;
		if (!$newDocument instanceof Document)
		{
			return Result::createByErrorData(message: 'Cant create new document by template');
		}

		return new CreateDocumentResult($newDocument);
	}

	private function replaceDocumentFile(
		Document $document,
		DocumentBlankReplacementConfig $replacementConfig,
	): Main\Result
	{
		$workingFileId = $replacementConfig->blankReplacementFileId;

		if ($replacementConfig->copyFileForBlank)
		{
			$copiedFile = $this->fileRepository->copyById($workingFileId);
			if ($copiedFile === null || $copiedFile->id === null)
			{
				return (new Main\Result())->addError(new Error('Failed to copy file'));
			}

			$workingFileId = $copiedFile->id;
		}

		$scenario = DocumentScenario::isB2EScenario($document->scenario)
			? BlankScenario::B2E
			: BlankScenario::B2B
		;

		$createBlankResult = $this->blankService->createFromFileIds([$workingFileId], $scenario);
		if (!$createBlankResult->isSuccess())
		{
			if ($replacementConfig->copyFileForBlank)
			{
				$deleteResult = $this->fileRepository->deleteById($workingFileId);
				if (!$deleteResult->isSuccess())
				{
					$createBlankResult->addErrors($deleteResult->getErrors());
				}
			}

			return Result::createByMainResult($createBlankResult);
		}

		$blankId = (int)$createBlankResult->getId();
		if ($blankId < 1)
		{
			return (new Main\Result())->addError(new Error('New blank is not created'));
		}

		$result = $this->documentService->changeBlank(
			$document->uid,
			$blankId,
			$replacementConfig->copyBlocksOnFileReplace,
		);
		if (!$result->isSuccess())
		{
			$rollbackResult = $this->blankService->rollbackById($blankId);
			if (!$rollbackResult->isSuccess())
			{
				$result->addErrors($rollbackResult->getErrors());
			}
		}

		return $result;
	}

	private function handleFailure(Main\Result $result, Document $document): Main\Result
	{
		if ($document->id === null)
		{
			return $result;
		}

		$rollbackResult = $this->documentService->rollbackDocument($document->id);
		if (!$rollbackResult->isSuccess())
		{
			$result->addErrors($rollbackResult->getErrors());
		}

		return $result;
	}
}
