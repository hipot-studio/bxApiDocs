<?php

namespace Bitrix\Crm\Tour\ClientFields;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Localization\Loc;

class ClientFieldsSmartDocumentList extends AbstractClientFieldsEntityList
{
	protected const OPTION_NAME = 'client-fields-smart-document-list';

	protected function canShowByEntityTypeId(): bool
	{
		return $this->entityTypeId === \CCrmOwnerType::SmartDocument;
	}

	protected function canShowByPermissions(): bool
	{
		$entityTypePermissions = Container::getInstance()->getUserPermissions()->entityType();

		return $entityTypePermissions->canReadItems(\CCrmOwnerType::Contact);
	}

	protected function getTitle(): string
	{
		return (string)Loc::getMessage('CRM_TOUR_CLIENT_FIELDS_SMART_DOCUMENT_LIST_TITLE');
	}

	protected function getText(): string
	{
		return (string)Loc::getMessage('CRM_TOUR_CLIENT_FIELDS_SMART_DOCUMENT_LIST_TEXT');
	}

	protected function getArticleId(): int
	{
		return 26378460;
	}
}
