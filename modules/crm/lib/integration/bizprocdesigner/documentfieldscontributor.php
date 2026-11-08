<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizprocDesigner;

use Bitrix\BizprocDesigner\Public\Document\Interface\DocumentFieldsContributor as Contributor;
use Bitrix\Crm\Integration\BizProc\Document\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Localization\Loc;

/**
 * Fields the new designer shows on top of the base crm document fields: PARENT_ID_* of the classic
 * documents, which lack them unlike the factory ones, and catalog product ids of documents with products.
 */
class DocumentFieldsContributor implements Contributor
{
	private const CLASSIC_DOCUMENT_CLASSES = [
		\CCrmDocumentLead::class,
		\CCrmDocumentDeal::class,
		\CCrmDocumentCompany::class,
		\CCrmDocumentContact::class,
	];

	private const PRODUCT_IDS_CATALOG = 'PRODUCT_IDS_CATALOG';

	public function getDocumentFields(array $documentType): array
	{
		$entityTypeId = \CCrmOwnerType::ResolveID((string)($documentType[2] ?? ''));
		$documentClass = (string)($documentType[1] ?? '');

		$fields =
			in_array($documentClass, self::CLASSIC_DOCUMENT_CLASSES, true)
				? Item::getParentFields($entityTypeId)
				: []
		;

		if (Container::getInstance()->getFactory($entityTypeId)?->isLinkWithProductsEnabled())
		{
			$fields[self::PRODUCT_IDS_CATALOG] = [
				'Name' => Loc::getMessage('CRM_BPD_DOCUMENT_FIELD_PRODUCT_IDS_CATALOG') ?? '',
				'Type' => 'int',
				'Multiple' => true,
				'Editable' => false,
				'Required' => false,
			];
		}

		return $fields;
	}
}
