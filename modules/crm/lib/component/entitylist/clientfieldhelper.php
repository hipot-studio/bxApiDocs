<?php

namespace Bitrix\Crm\Component\EntityList;


use Bitrix\Main\NotSupportedException;
use Bitrix\Main\Localization\Loc;

class ClientFieldHelper
{
	protected int $entityTypeId;
	protected ?string $fieldPrefix = null;
	protected $fieldsWithoutPrefix = [
		\CCrmOwnerType::Company => [
			'COMPANY_TYPE'
		]
	];

	public function __construct(int $entityTypeId)
	{
		$this->entityTypeId = $entityTypeId;
		if (!in_array($this->entityTypeId, [\CCrmOwnerType::Contact, \CCrmOwnerType::Company]))
		{
			throw new NotSupportedException(
				\CCrmOwnerType::ResolveName($this->entityTypeId) . 'is not a client entity'
			);
		}
	}

	public function isClientFilterFieldId(string $fieldIdWithOperation): bool
	{
		if (!str_contains($fieldIdWithOperation, $this->getFieldPrefix())) // micro optimization
		{
			return false;
		}

		$fieldId = \CSqlUtil::GetFilterOperation($fieldIdWithOperation)['FIELD'] ?? '';

		return $this->isClientFieldId($fieldId);
	}

	public function isClientFieldId(string $fieldId): bool
	{
		return str_starts_with($fieldId, $this->getFieldPrefix());
	}

	/**
	 * Will return "CONTACT_" or "COMPANY_"
	 *
	 * @return string
	 */
	public function getFieldPrefix(): string
	{
		if ($this->fieldPrefix === null)
		{
			$this->fieldPrefix = \CCrmOwnerType::ResolveName($this->entityTypeId) . '_';
		}

		return $this->fieldPrefix;
	}

	/**
	 * Remove CONTACT_/COMPANY_ from beginning of $fieldId
	 *
	 * @param string $fieldId
	 * @return string
	 */
	public function getFieldIdWithoutPrefix(string $fieldId): string
	{
		$fieldsWithoutPrefix = $this->fieldsWithoutPrefix[$this->entityTypeId] ?? [];
		if (!in_array($fieldId, $fieldsWithoutPrefix))
		{
			$fieldPrefix = $this->getFieldPrefix();
			$fieldId = mb_substr($fieldId, mb_strlen($fieldPrefix));
		}

		return $fieldId;
	}

	/**
	 * Add CONTACT_/COMPANY_ to beginning of $fieldId
	 *
	 * @param string $fieldId
	 * @return string
	 */
	public function addPrefixToFieldId(string $fieldId): string
	{
		$fieldsWithoutPrefix = $this->fieldsWithoutPrefix[$this->entityTypeId] ?? [];

		if (in_array($fieldId, $fieldsWithoutPrefix))
		{
			return $fieldId;
		}

		return $this->getFieldPrefix() . $fieldId;
	}

	public function addRelationToFieldId(string $fieldId): string
	{
		return sprintf(
			'%s.%s',
			\CCrmOwnerType::ResolveName($this->entityTypeId),
			$fieldId,
		);
	}

	/**
	 * Get field name (caption)
	 * Entity name prefix may be included, like "Contact: Last name"
	 *
	 * @param string $fieldIdWithoutPrefix
	 * @param bool $addPrefix
	 * @return string
	 */
	public function getFieldName(string $fieldIdWithoutPrefix, bool $addPrefix = false): string
	{
		$entity = $this->getEntityClass();
		$name = $entity::GetFieldCaption($fieldIdWithoutPrefix);

		return
			$addPrefix
				? $this->addPrefixToFieldName($name)
				: $name
		;
	}

	/**
	 * Add entity name prefix to field name (caption)
	 * Result looks like "Contact: $fieldName"
	 *
	 * @param string $fieldName
	 * @return string
	 */
	public function addPrefixToFieldName(string $fieldName): string
	{
		$namePattern = '';
		switch ($this->entityTypeId)
		{
			case \CCrmOwnerType::Contact:
				$namePattern = Loc::getMessage('CRM_CLIENT_DATA_PROVIDER_CONTACT');
				break;
			case \CCrmOwnerType::Company:
				$namePattern = Loc::getMessage('CRM_CLIENT_DATA_PROVIDER_COMPANY');
				break;
		}

		if (mb_strpos($namePattern, '#TITLE#') === false)
		{
			$namePattern .= ': #TITLE#';
		}

		return str_replace('#TITLE#', $fieldName, $namePattern);
	}

	public function getEntityTitle(): string
	{
		switch ($this->entityTypeId)
		{
			case \CCrmOwnerType::Contact:
				return Loc::getMessage('CRM_CLIENT_DATA_PROVIDER_CONTACT_TITLE');
			case \CCrmOwnerType::Company:
				return Loc::getMessage('CRM_CLIENT_DATA_PROVIDER_COMPANY_TITLE');
		}

		return '';
	}
	
	public function getEntityClass(): string
	{
		switch ($this->entityTypeId)
		{
			case \CCrmOwnerType::Contact:
				return \CCrmContact::class;

			case \CCrmOwnerType::Company:
				return \CCrmCompany::class;
		}
	}

	public function normalizeFilter(array $filter): array
	{
		foreach ($filter as $fieldIdWithOperation => $fieldValue)
		{
			if (is_int($fieldIdWithOperation) && is_array($fieldValue))
			{
				$filter[$fieldIdWithOperation] = $this->normalizeFilter($fieldValue);
			}
			elseif ($this->isClientFilterFieldId($fieldIdWithOperation))
			{
				$normalizedFieldId = $this->normalizeFilterFieldId($fieldIdWithOperation);
				if ($normalizedFieldId !== null)
				{
					$filter[$normalizedFieldId] = $fieldValue;
					unset($filter[$fieldIdWithOperation]);
				}
			}
		}

		return $filter;
	}

	public function normalizeFilterFieldId(string $fieldIdWithOperation): ?string
	{
		$fieldId = \CSqlUtil::GetFilterOperation($fieldIdWithOperation)['FIELD'] ?? null;
		if ($fieldId === null)
		{
			return null;
		}

		$fieldIdStart = mb_strpos($fieldIdWithOperation, $fieldId);
		if ($fieldIdStart === false)
		{
			return null;
		}

		$operation = mb_substr($fieldIdWithOperation, 0, $fieldIdStart);
		$normalizedFieldId = $this->normalizeFieldId($fieldId);

		return
			$normalizedFieldId !== null
				? $operation . $normalizedFieldId
				: null
			;
	}

	public function normalizeFieldId(string $fieldId): ?string
	{
		if (!$this->isClientFieldId($fieldId))
		{
			return null;
		}

		return $this->addRelationToFieldId(
			$this->getFieldIdWithoutPrefix($fieldId),
		);
	}
}
