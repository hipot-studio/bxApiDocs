<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Requisite;

use Bitrix\Crm\Item;
use Bitrix\Crm\Service;
use Bitrix\Main;
use CCrmInvoice;
use CCrmOwnerType;

/**
 * Who the client and who the seller of a CRM entity is - the question a link row is checked against:
 * a requisite may only be chosen among those of the client, and a requisite of the seller only among
 * those of the company the entity names as "my company".
 *
 * A company outranks a contact: an entity that names both answers with the company. An entity that
 * names neither answers with zeros, and an entity that does not exist at all is refused with
 * {@see self::ERR_ENTITY_NOT_FOUND}.
 *
 * The three ways to read those fields are not interchangeable. The old invoice keeps them in `UF_*`
 * columns and is read past the permission check; the smart invoice is the only type read through
 * its item, because only there do the parent fields resolve; every other type is read by a query of
 * its own data class.
 *
 * The identifiers of the entity are untyped on purpose: the type is compared strictly against the
 * old invoice, and a numeric string coerced to int would take a branch legacy never took.
 *
 * @internal
 */
final class EntityClientSellerResolver
{
	public const ERR_ENTITY_NOT_FOUND = 203;

	/** @var array<int, array<int, string>>|null entityTypeId => parentEntityTypeId => field name */
	private ?array $parentEntityFieldMap = null;

	/** @return array<int, array<int, string>> */
	public function getParentEntityFieldMap(): array
	{
		return $this->parentEntityFieldMap ??= [
			CCrmOwnerType::Deal => [
				CCrmOwnerType::Quote => 'QUOTE_ID',
			],
			CCrmOwnerType::Quote => [
				CCrmOwnerType::Deal => 'DEAL_ID',
			],
			CCrmOwnerType::Invoice => [
				CCrmOwnerType::Quote => 'UF_QUOTE_ID',
				CCrmOwnerType::Deal => 'UF_DEAL_ID',
			],
			CCrmOwnerType::SmartInvoice => [
				CCrmOwnerType::Quote => Service\ParentFieldManager::getParentFieldName(CCrmOwnerType::Quote),
				CCrmOwnerType::Deal => Service\ParentFieldManager::getParentFieldName(CCrmOwnerType::Deal),
			],
		];
	}

	/**
	 * @param mixed $options `GET_PARENT_ENTITY_FILEDS` (the typo is the published key) asks for the
	 *                       identifiers of the parent entities alongside the client and the seller
	 * @return array<string, mixed> CLIENT_ENTITY_TYPE_ID, CLIENT_ENTITY_ID, MYCOMPANY_ID and,
	 *                              on demand, one key per parent field
	 * @throws Main\SystemException when the entity does not exist
	 */
	public function getClientSellerInfo(mixed $entityTypeId, mixed $entityId, mixed $options = []): array
	{
		$getParentEntityFields = (
			is_array($options) && isset($options['GET_PARENT_ENTITY_FILEDS'])
			&& ($options['GET_PARENT_ENTITY_FILEDS'] === 'Y' || $options['GET_PARENT_ENTITY_FILEDS'] === true)
		);

		$entityNotFound = false;
		$result = [
			'CLIENT_ENTITY_TYPE_ID' => CCrmOwnerType::Undefined,
			'CLIENT_ENTITY_ID' => 0,
			'MYCOMPANY_ID' => 0,
		];
		$parentFieldMap = $this->getParentEntityFieldMap();
		$parentFields = [];
		if ($getParentEntityFields && is_array($parentFieldMap[$entityTypeId] ?? null))
		{
			$parentFields = array_values($parentFieldMap[$entityTypeId]);
		}

		if ($entityTypeId === CCrmOwnerType::Invoice)
		{
			$select = ['ID', 'UF_COMPANY_ID', 'UF_CONTACT_ID', 'UF_MYCOMPANY_ID'];
			$res = CCrmInvoice::GetList(
				[],
				['ID' => $entityId, 'CHECK_PERMISSIONS' => 'N'],
				false,
				false,
				array_merge($select, $parentFields)
			);
			$row = $res->Fetch();
			if (is_array($row))
			{
				if (isset($row['UF_COMPANY_ID']) && $row['UF_COMPANY_ID'] > 0)
				{
					$result['CLIENT_ENTITY_TYPE_ID'] = CCrmOwnerType::Company;
					$result['CLIENT_ENTITY_ID'] = (int)$row['UF_COMPANY_ID'];
				}
				else if (isset($row['UF_CONTACT_ID']) && $row['UF_CONTACT_ID'] > 0)
				{
					$result['CLIENT_ENTITY_TYPE_ID'] = CCrmOwnerType::Contact;
					$result['CLIENT_ENTITY_ID'] = (int)$row['UF_CONTACT_ID'];
				}

				if (isset($row['UF_MYCOMPANY_ID']) && $row['UF_MYCOMPANY_ID'] > 0)
				{
					$result['MYCOMPANY_ID'] = (int)$row['UF_MYCOMPANY_ID'];
				}
			}
			else
			{
				$entityNotFound = true;
			}
		}
		else
		{
			$factory = Service\Container::getInstance()->getFactory((int)$entityTypeId);
			if ($factory)
			{
				if (
					!$getParentEntityFields
					|| empty($parentFields)
					|| $entityTypeId != CCrmOwnerType::SmartInvoice
				)
				{
					[$result, $entityNotFound] = $this->clientSellerInfoByOrm(
						$factory,
						(int)$entityId,
						$result,
						$getParentEntityFields,
						$parentFields,
						$entityNotFound
					);
				}
				else
				{
					[$result, $entityNotFound] = $this->clientSellerInfoByFactory(
						$factory,
						(int)$entityId,
						$result,
						$getParentEntityFields,
						$parentFields,
						$entityNotFound
					);
				}
			}
			else
			{
				$entityNotFound = true;
			}
		}

		if ($entityNotFound)
		{
			throw new Main\SystemException('Entity is not found', self::ERR_ENTITY_NOT_FOUND);
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $result
	 * @param string[] $parentFields
	 * @return array{array<string, mixed>, bool}
	 */
	private function clientSellerInfoByFactory(
		Service\Factory $factory,
		int $entityId,
		array $result,
		bool $getParentEntityFields,
		array $parentFields,
		bool $entityNotFound
	): array
	{
		$item = $factory->getItem($entityId);
		if ($item)
		{
			$companyId = $item->getCompanyId();
			$contactId = $item->getContactId();
			if ($companyId > 0)
			{
				$result['CLIENT_ENTITY_TYPE_ID'] = CCrmOwnerType::Company;
				$result['CLIENT_ENTITY_ID'] = $companyId;
			}
			elseif ($contactId > 0)
			{
				$result['CLIENT_ENTITY_TYPE_ID'] = CCrmOwnerType::Contact;
				$result['CLIENT_ENTITY_ID'] = $contactId;
			}

			if ($item->hasField(Item::FIELD_NAME_MYCOMPANY_ID))
			{
				$myCompanyId = $item->getMycompanyId();
				if ($myCompanyId > 0)
				{
					$result['MYCOMPANY_ID'] = $myCompanyId;
				}
			}

			if ($getParentEntityFields)
			{
				foreach ($parentFields as $fieldName)
				{
					if ($item->hasField($fieldName) && $item->get($fieldName) > 0)
					{
						$result[$fieldName] = $item->get($fieldName);
					}
				}
			}
		}
		else
		{
			$entityNotFound = true;
		}

		return [$result, $entityNotFound];
	}

	/**
	 * @param array<string, mixed> $result
	 * @param string[] $parentFields
	 * @return array{array<string, mixed>, bool}
	 */
	private function clientSellerInfoByOrm(
		Service\Factory $factory,
		int $entityId,
		array $result,
		bool $getParentEntityFields,
		array $parentFields,
		bool $entityNotFound
	): array
	{
		$select = [
			Item::FIELD_NAME_ID,
		];

		$companyIdFieldName = $factory->getEntityFieldNameByMap(Item::FIELD_NAME_COMPANY_ID);
		if ($factory->isFieldExists($companyIdFieldName))
		{
			$select[] = $companyIdFieldName;
		}

		$contactIdFieldName = $factory->getEntityFieldNameByMap(Item::FIELD_NAME_CONTACT_ID);
		if ($factory->isFieldExists($contactIdFieldName))
		{
			$select[] = $contactIdFieldName;
		}

		$myCompanyFieldName = $factory->getEntityFieldNameByMap(Item::FIELD_NAME_MYCOMPANY_ID);
		if ($factory->isFieldExists($myCompanyFieldName))
		{
			$select[] = $myCompanyFieldName;
		}

		foreach ($parentFields as $parentField)
		{
			if ($factory->isFieldExists($parentField))
			{
				$select[] = $parentField;
			}
		}

		$item = $factory->getDataClass()::query()
			->setSelect($select)
			->where('ID', $entityId)
			->fetch()
		;

		if ($item)
		{
			$companyId = $item[$companyIdFieldName] ?? 0;
			$contactId = $item[$contactIdFieldName] ?? 0;
			if ($companyId > 0)
			{
				$result['CLIENT_ENTITY_TYPE_ID'] = CCrmOwnerType::Company;
				$result['CLIENT_ENTITY_ID'] = $companyId;
			}
			elseif ($contactId > 0)
			{
				$result['CLIENT_ENTITY_TYPE_ID'] = CCrmOwnerType::Contact;
				$result['CLIENT_ENTITY_ID'] = $contactId;
			}

			$myCompanyId = $item[$myCompanyFieldName] ?? 0;
			if ($myCompanyId > 0)
			{
				$result['MYCOMPANY_ID'] = $myCompanyId;
			}

			if ($getParentEntityFields)
			{
				foreach ($parentFields as $fieldName)
				{
					if (isset($item[$fieldName]) && $item[$fieldName] > 0)
					{
						$result[$fieldName] = $item[$fieldName];
					}
				}
			}
		}
		else
		{
			$entityNotFound = true;
		}

		return [$result, $entityNotFound];
	}
}
