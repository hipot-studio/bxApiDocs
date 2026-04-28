<?php

namespace Bitrix\Sign\Operation\Document\Template;

use Bitrix\Main;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Document\BindingCollection;
use Bitrix\Sign\Item\Document\Config\DocumentBlankReplacementConfig;
use Bitrix\Sign\Item\Document\Template;
use Bitrix\Sign\Operation;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Result\CreateDocumentResult;
use Bitrix\Sign\Result\Result;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\DocumentService;

final class CreateDocumentFromTemplate implements Contract\Operation
{
	private readonly DocumentService $documentService;
	private readonly DocumentRepository $documentRepository;

	public function __construct(
		private readonly Template $template,
		private readonly Document $templateDocument,
		private readonly int $createdByUserId,
		private readonly bool $excludeRejected = true,
		private readonly ?BindingCollection $bindings = null,
		private readonly ?DocumentBlankReplacementConfig $blankReplacementConfig = null,
		?DocumentService $documentService = null,
		?DocumentRepository $documentRepository = null,
	)
	{
		$container = Container::instance();

		$this->documentService = $documentService ?? $container->getDocumentService();
		$this->documentRepository = $documentRepository ?? $container->getDocumentRepository();
	}

	public function launch(): CreateDocumentResult|Main\Result
	{
		$createResult = (new Operation\Document\Copy(
			document: $this->templateDocument,
			createdByUserId: $this->createdByUserId,
			bindings: $this->bindings,
			excludeRejected: $this->excludeRejected,
			blankReplacementConfig: $this->blankReplacementConfig,
		))->launch();
		if (!$createResult instanceof CreateDocumentResult)
		{
			return $createResult;
		}

		$newDocument = $createResult->document;
		if ($newDocument->id === null)
		{
			return Result::createByErrorData(message: 'Document is not created.');
		}

		$newDocument->title = $this->template->title;
		$result = $this->documentRepository->update($newDocument);
		if (!$result->isSuccess())
		{
			$rollbackResult = $this->documentService->rollbackDocument($newDocument->id);
			$result->addErrors($rollbackResult->getErrors());

			return $result;
		}

		return $createResult;
	}
}
