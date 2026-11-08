<?php

namespace Bitrix\Sign\Service\Sign\Document;

use Bitrix\Main;
use Bitrix\Main\Result;
use Bitrix\Sign\Item;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Type;
use Bitrix\Sign\Service;
use Bitrix\Sign\Type\DocumentScenario;

class ProviderCodeService
{
	private readonly DocumentRepository $documentRepository;
	private readonly Service\Api\B2e\ProviderCodeService $apiProviderCodeService;

	public function __construct(
		?DocumentRepository $documentRepository = null,
		?Service\Api\B2e\ProviderCodeService $apiProviderCodeService = null,
	)
	{
		$container = Service\Container::instance();
		$this->documentRepository = $documentRepository ?? $container->getDocumentRepository();
		$this->apiProviderCodeService = $apiProviderCodeService ?? $container->getApiProviderCodeService();
	}

	public function loadByDocument(Item\Document $document): Main\Result
	{
		if ($document->providerCode !== null)
		{
			return new Main\Result();
		}
		if (!DocumentScenario::isB2eScenarioByDocument($document))
		{
			return (new Main\Result())->addError(new Main\Error("Cant load provider info. Document is not B2E"));
		}

		$companyUid = $document->companyUid;
		if ($companyUid === null)
		{
			return (new Main\Result())->addError(new Main\Error("Cant load provider info. Company uid is not set"));
		}

		$providerCode = $this->apiProviderCodeService->loadProviderCode($companyUid);
		if ($providerCode === null)
		{
			return (new Main\Result())->addError(new Main\Error("Cant load provider info. Provider code is not set"));
		}
		$providerCode = Type\ProviderCode::createFromProviderLikeString($providerCode);
		if ($providerCode === null)
		{
			return (new Main\Result())->addError(new Main\Error("Cant load provider info. Provider code is not valid"));
		}

		$document->providerCode = $providerCode;

		return $this->documentRepository->update($document);
	}

	public function updateProviderCode(Item\Document $document, string $providerCode): Result
	{
		$validationResult = $this->validateProviderCode($providerCode);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		if ($document->companyUid === null)
		{
			$document->providerCode = $providerCode;

			return $this->documentRepository->update($document);
		}
		$companyUid = $document->companyUid;

		return $this->documentRepository->updateProviderCodeToDocumentsByCompanyUid($companyUid, $providerCode);
	}

	/**
	 * Writes the provider code of exactly one document, without the document status guard.
	 *
	 * @internal the status guard lives in the entry point of the domain, so a caller outside it would
	 *           write the code of a document that may no longer be changed.
	 *
	 * @see \Bitrix\Sign\Service\Sign\DocumentService::modifyProviderCodeForSingleDocument() guarded entry point.
	 * @see self::updateProviderCode() spreads the code over the company documents instead.
	 */
	public function updateProviderCodeToDocument(Item\Document $document, string $providerCode): Result
	{
		$validationResult = $this->validateProviderCode($providerCode);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$document->providerCode = $providerCode;

		return $this->documentRepository->update($document);
	}

	private function validateProviderCode(string $providerCode): Result
	{
		return Type\ProviderCode::isValid($providerCode)
			? new Result()
			: (new Result())->addError(new Main\Error("Invalid provider code. `$providerCode`"))
		;
	}
}