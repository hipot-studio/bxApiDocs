<?php

namespace Bitrix\Sign\Service\Integration\Crm;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory\SmartDocument;
use Bitrix\Main\Loader;
use Bitrix\Sign\Access\Permission\PermissionDictionary;
use Bitrix\Sign\Item;
use Bitrix\Sign\Operation\CheckDocumentAccess;
use Bitrix\Sign\Type\Member\EntityType;

final class AccessService
{
	public function canReadSmartDocumentContacts(): bool
	{
		if (!Loader::includeModule('crm'))
		{
			return false;
		}

		$factory = Container::getInstance()->getFactory(\CCrmOwnerType::Contact);
		$category = $factory?->getCategoryByCode(SmartDocument::CONTACT_CATEGORY_CODE);
		if (!$category)
		{
			return true;
		}

		return Container::getInstance()
			->getUserPermissions()
			->entityType()
			->canReadItemsInCategory(\CCrmOwnerType::Contact, $category->getId());
	}

	public function canCurrentUserViewDocument(Item\Document $document): bool
	{
		if (!Loader::includeModule('crm'))
		{
			return false;
		}

		$permissionId = match ($document->entityTypeId)
		{
			\CCrmOwnerType::SmartB2eDocument => PermissionDictionary::SIGN_CRM_SMART_B2E_DOC_READ,
			\CCrmOwnerType::SmartDocument => PermissionDictionary::SIGN_CRM_SMART_DOCUMENT_READ,
			default => null,
		};
		if ($permissionId === null)
		{
			return false;
		}

		return (new CheckDocumentAccess($document, $permissionId))
			->launch()
			->isSuccess()
		;
	}

	public function canReadContact(int $contactId): bool
	{
		if ($contactId <= 0)
		{
			return false;
		}

		if (!Loader::includeModule('crm'))
		{
			return false;
		}

		return Container::getInstance()
			->getUserPermissions()
			->item()
			->canRead(\CCrmOwnerType::Contact, $contactId)
		;
	}

	public function isContactReadDeniedByMember(Item\Member $member): bool
	{
		return $member->entityType === EntityType::CONTACT
			&& !$this->canReadContact((int)$member->entityId)
		;
	}
}
