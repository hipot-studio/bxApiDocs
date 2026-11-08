<?php

namespace Bitrix\Sign\Service\Integration\Crm;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Type\DocumentScenario;
use Bitrix\Sign\Item;
use Bitrix\Main;

class B2eDocumentService
{
	public function setMyCompany(Item\Document $document, int $companyId): Main\Result
	{
		$documentResult = $this->checkDocument($document);
		if (!$documentResult->isSuccess())
		{
			return $documentResult;
		}

		$company = Container::getInstance()
			->getFactory(\CCrmOwnerType::Company)
			->getItem($companyId)
		;

		if ($company === null)
		{
			return (new Main\Result())->addError(new Main\Error(
				(string)Loc::getMessage('SIGN_SERVICE_CRM_B2E_DOCUMENT_COMPANY_NOT_FOUND'),
				'COMPANY_DOESNT_EXIST',
			));
		}

		return $this->saveMyCompany($document, $companyId);
	}

	/**
	 * Detaches the card from its company. The card mirrors the company member of the document,
	 * so a document left without members points at no company.
	 */
	public function resetMyCompany(Item\Document $document): Main\Result
	{
		$documentResult = $this->checkDocument($document);
		if (!$documentResult->isSuccess())
		{
			return $documentResult;
		}

		return $this->saveMyCompany($document, 0);
	}

	private function checkDocument(Item\Document $document): Main\Result
	{
		$result = new Main\Result();
		if (!Main\Loader::includeModule('crm'))
		{
			return $result->addError(new Main\Error('Module `crm` is not installed'));
		}

		if (!DocumentScenario::isB2EScenario($document->scenario))
		{
			return $result->addError(new Main\Error('Invalid document scenario'));
		}

		if ($document->entityId === null || $document->entityId <= 0)
		{
			return $result->addError(new Main\Error('Invalid document `entityId`'));
		}

		return $result;
	}

	private function saveMyCompany(Item\Document $document, int $companyId): Main\Result
	{
		$smartB2eDocument = Container::getInstance()
			->getFactory(\CCrmOwnerType::SmartB2eDocument)
			->getItem($document->entityId)
		;

		if ($smartB2eDocument === null)
		{
			return (new Main\Result())->addError(
				new Main\Error(
					(string)Loc::getMessage('SIGN_SERVICE_CRM_B2E_DOCUMENT_CARD_NOT_FOUND'),
					'DOCUMENT_CARD_DOESNT_EXIST',
				),
			);
		}

		$smartB2eDocument->setMycompanyId($companyId);

		return $smartB2eDocument->save();
	}
}
