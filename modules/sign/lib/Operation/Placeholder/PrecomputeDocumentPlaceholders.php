<?php

namespace Bitrix\Sign\Operation\Placeholder;

use Bitrix\Main;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Service\Api\Document\ApiDocumentPlaceholderService;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Document\Placeholder\PlaceholderCacheService;

final class PrecomputeDocumentPlaceholders implements Contract\Operation
{
	private bool $placeholderServiceCalled = false;

	private readonly Storage $storage;
	private readonly DocumentRepository $documentRepository;
	private readonly PlaceholderCacheService $placeholderCacheService;
	private readonly ApiDocumentPlaceholderService $placeholderApiService;

	public function __construct(
		private readonly string $documentUid,
		private readonly int $documentId,
		private readonly int $blankId,
		?Storage $storage = null,
		?DocumentRepository $documentRepository = null,
		?PlaceholderCacheService $placeholderCacheService = null,
		?ApiDocumentPlaceholderService $placeholderApiService = null,
	)
	{
		$container = Container::instance();
		$this->storage = $storage ?? Storage::instance();
		$this->documentRepository = $documentRepository ?? $container->getDocumentRepository();
		$this->placeholderCacheService = $placeholderCacheService
			?? $container->getDocumentPlaceholderCacheService()
		;
		$this->placeholderApiService = $placeholderApiService
			?? $container->getApiDocumentPlaceholderService()
		;
	}

	public function launch(): Main\Result
	{
		$result = new Main\Result();
		if (!$this->storage->isPlaceholderRestrictionEnabled())
		{
			return $result;
		}

		$currentDocument = $this->documentRepository->getById($this->documentId);
		if ($currentDocument?->blankId !== $this->blankId)
		{
			return $result;
		}

		$this->placeholderServiceCalled = true;
		$operation = new GetPlaceholdersFromDocument($this->documentUid, $this->placeholderApiService);
		$loadResult = $operation->launch();
		if (!$loadResult->isSuccess())
		{
			return $loadResult;
		}

		$currentDocument = $this->documentRepository->getById($this->documentId);
		if ($currentDocument?->blankId !== $this->blankId)
		{
			return $result;
		}

		$this->placeholderCacheService->setRawPlaceholders(
			$this->documentId,
			$this->blankId,
			$operation->getPlaceholders(),
		);

		$currentDocument = $this->documentRepository->getById($this->documentId);
		if ($currentDocument?->blankId !== $this->blankId)
		{
			$this->placeholderCacheService->deleteRawPlaceholders($this->documentId, $this->blankId);
		}

		return $result;
	}

	public function wasPlaceholderServiceCalled(): bool
	{
		return $this->placeholderServiceCalled;
	}
}
