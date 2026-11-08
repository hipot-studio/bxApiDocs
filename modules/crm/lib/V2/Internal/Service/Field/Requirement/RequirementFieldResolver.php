<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Field\Requirement;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Internal\Service\Item\FieldRegistry;
use Bitrix\Crm\V2\Internal\Service\Item\FieldType;
use Bitrix\Crm\V2\Internal\Service\Item\LegacyFieldNameResolver;
use Bitrix\Crm\V2\Public\Entity\Field\RequirementError;
use Bitrix\Crm\V2\Public\Entity\Field\RequirementException;
use Bitrix\Crm\V2\Public\EntityType;

final class RequirementFieldResolver
{
	private readonly CustomFieldRegistry $customFieldRegistry;

	public function __construct(?CustomFieldRegistry $customFieldRegistry = null)
	{
		$this->customFieldRegistry = $customFieldRegistry ?? CustomFieldRegistry::getInstance();
	}

	public function resolve(EntityType $entityType, string $fieldId): ResolvedRequirementField
	{
		if (str_starts_with($fieldId, 'UF_'))
		{
			return $this->resolveCustomField($entityType, $fieldId);
		}

		$fieldRegistry = FieldRegistry::getInstance($entityType);
		$fieldDescriptor = $fieldRegistry->getField($fieldId);
		if ($fieldDescriptor === null)
		{
			throw new RequirementException(RequirementError::FIELD_NOT_FOUND);
		}

		if ($fieldDescriptor->type === FieldType::Utm)
		{
			return new ResolvedRequirementField($fieldId, null, false);
		}

		$legacyFieldNames = LegacyFieldNameResolver::resolve($fieldId, $fieldRegistry);
		$legacyFieldName = count($legacyFieldNames) === 1 ? $legacyFieldNames[0] : null;
		if ($legacyFieldName === null)
		{
			throw new RequirementException(RequirementError::FIELD_NOT_FOUND);
		}

		$factory = Container::getInstance()->getFactory($entityType->getId());
		$fieldsInfo = $factory?->getFieldsInfo() ?? [];
		$fieldInfo = $fieldsInfo[$legacyFieldName] ?? [];

		return new ResolvedRequirementField(
			$fieldId,
			$legacyFieldName,
			\CCrmFieldInfoAttr::isFieldHasAttribute($fieldInfo, \CCrmFieldInfoAttr::Required),
		);
	}

	private function resolveCustomField(EntityType $entityType, string $fieldId): ResolvedRequirementField
	{
		$descriptor = $this->customFieldRegistry->getEntityDescriptorsMap($entityType)[$fieldId] ?? null;
		if ($descriptor === null)
		{
			throw new RequirementException(RequirementError::FIELD_NOT_FOUND);
		}

		return new ResolvedRequirementField($fieldId, $fieldId, $descriptor->isRequired);
	}
}
