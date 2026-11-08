<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item;

use Bitrix\Crm\V2\Public\Entity\Item\Contact;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\Entity\Item\Quote;

final class ItemFieldMapper
{
	private const DICTIONARY_RELATION_FIELDS = [
		'stage',
		'previousStage',
		'stageSemantic',
		'source',
		'category',
		'currency',
		'webform',
		'type',
		'personType',
		'honorific',
		'industry',
		'employees',
	];

	private const DICTIONARY_LOADER_FIELDS = [
		'stage',
		'previousStage',
		'stageSemantic',
		'source',
		'category',
		'currency',
		'webform',
		'type',
	];

	private const EMPLOYEE_RELATION_FIELDS = [
		'createdBy',
		'updatedBy',
		'lastActivityBy',
		'assignedBy',
		'movedBy',
		'observers',
	];

	public function mapDtoFieldNameToItem(string $fieldName): ?string
	{
		if ($this->isRelatedCrmObjectIdField($fieldName))
		{
			return null;
		}

		if ($this->isCustomFieldDataField($fieldName))
		{
			return $this->getCustomFieldDataRelatedField($fieldName);
		}

		return match ($fieldName)
		{
			'entityTypeId', 'canRead', 'url' => null,
			'beginTime' => Item::beginDate,
			'closeTime' => Item::closeDate,
			'actualTime' => Quote::actualDate,
			'birthdayTime' => Contact::birthdate,
			'industryId', 'industry' => 'industry',
			'employeesId', 'employees' => 'employees',
			'type' => 'typeId',
			'personType' => 'personTypeId',
			'honorific', 'honorificId' => Contact::honorific,
			'lastCommunication', 'utm' => null,
			'contactId', 'contactsId' => Item::contactBindings,
			'companiesId', 'companies' => Item::companyBindings,
			'phone', 'hasPhone' => Item::phones,
			'email', 'hasEmail' => Item::emails,
			'web', 'hasWeb' => Item::webs,
			'im', 'hasIm' => Item::ims,
			'createdBy' => Item::createdById,
			'updatedBy' => Item::updatedById,
			'lastActivityBy' => Item::lastActivityById,
			'assignedBy' => Item::assignedById,
			'movedBy' => 'movedById',
			'observers' => Item::observers,
			'observersId' => Item::observers,
			default => $fieldName,
		};
	}

	public static function isDictionaryRelationField(string $fieldName): bool
	{
		return in_array($fieldName, self::DICTIONARY_RELATION_FIELDS, true);
	}

	public static function requiresDictionaryLoader(string $fieldName): bool
	{
		return in_array($fieldName, self::DICTIONARY_LOADER_FIELDS, true);
	}

	public static function isEmployeeRelationField(string $fieldName): bool
	{
		return in_array($fieldName, self::EMPLOYEE_RELATION_FIELDS, true);
	}

	public function isCustomFieldDataField(string $fieldName): bool
	{
		return str_starts_with($fieldName, 'UF_') && str_ends_with($fieldName, '_data');
	}

	public function getCustomFieldDataRelatedField(string $fieldName): string
	{
		return substr($fieldName, 0, -strlen('_data'));
	}

	private function isRelatedCrmObjectIdField(string $fieldName): bool
	{
		return str_starts_with($fieldName, 'related') && str_ends_with($fieldName, 'Id');
	}
}
