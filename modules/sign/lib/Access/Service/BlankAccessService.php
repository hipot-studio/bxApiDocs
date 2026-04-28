<?php

namespace Bitrix\Sign\Access\Service;

use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\AccessController\AccessControllerFactory;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Item;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Type;

class BlankAccessService
{
	public function __construct(
		private readonly AccessControllerFactory $accessControllerFactory,
		private readonly DocumentRepository $documentRepository,
	)
	{
	}

	public function isUserHasReadAccessThroughLinkedDocuments(int $userId, Item\Blank $blank): bool
	{
		$accessController = $this->accessControllerFactory->createByUserId($userId);
		if ($accessController === null)
		{
			return false;
		}

		$blankDocuments = $this->documentRepository->listByBlankId($blank->id);

		return $blankDocuments->any(
			fn(Item\Document $document) =>
				!Type\DocumentStatus::isFinalByDocument($document)
				&& $this->checkDocumentReadAccess($accessController, $document, $blank->scenario),
		);
	}

	private function checkDocumentReadAccess(
		AccessController $accessController,
		Item\Document $document,
		string $blankScenario,
	): bool
	{
		if ($document->isTemplated())
		{
			return $accessController->checkByItem(ActionDictionary::ACTION_B2E_TEMPLATE_READ, $document);
		}

		if ((int)$document->entityId === 0)
		{
			return false;
		}

		$action = $blankScenario === Type\BlankScenario::B2E
			? ActionDictionary::ACTION_B2E_DOCUMENT_READ
			: ActionDictionary::ACTION_DOCUMENT_READ
		;

		return $accessController->checkByItem($action, $document);
	}
}
