<?php
namespace Bitrix\Crm\Integration\Rest\Configuration;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\DI\ServiceLocator;
use CCrmOwnerType;

/** @internal */
class Helper
{
	public function exportCrmDynamicTypesInfo(): array
	{
		/** @global \CUserTypeManager $USER_FIELD_MANAGER */
		global $USER_FIELD_MANAGER;

		$result = [];

		$typesMap = Container::getInstance()->getDynamicTypesMap()->load([
			'isLoadCategories' => true,
			'isLoadStages' => true,
		]);

		foreach ($typesMap->getTypes() as $type)
		{
			$entityTypeId = $type->getEntityTypeId();
			if (
				is_int($entityTypeId)
				&& CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId)
				&& $type->getCustomSectionId() <= 0
			)
			{
				$typeId = $type->getId();
				$userFieldEntityId =
					ServiceLocator::getInstance()
						->get('crm.type.factory')
						->getUserFieldEntityId($type->getId())
				;
				$result[$typeId] = [
					'entityTypeId' => $entityTypeId,
					'categoryIds' => [],
					'userFieldEntityId' => $userFieldEntityId,
					'userFieldNames' => [],
				];
				foreach ($typesMap->getCategories($type->getEntityTypeId()) as $category)
				{
					$result[$typeId]['categoryIds'][] = $category->getId();
				}

				$userFieldInfos = $USER_FIELD_MANAGER->GetUserFields(
					$userFieldEntityId,
					0,
					LANGUAGE_ID,
					false,
					['FIELD_NAME']
				);
				foreach ($userFieldInfos as $userFieldInfo)
				{
					$result[$typeId]['userFieldNames'][] = $userFieldInfo['FIELD_NAME'];
				}
			}
		}

		return $result;
	}

	public function getDynamicCategoryCode(int $entityTypeId, int $categoryId): string
	{
		return "DT{$entityTypeId}_$categoryId";
	}

	public function getDynamicEntityTypeIdByOldEntityTypeId(int $oldEntityTypeId, array $ratio): int
	{
		$newDynamicEntityTypeId = 0;

		$newDynamicEntityTypeIdRatioKey = CCrmOwnerType::DynamicTypePrefixName . $oldEntityTypeId;
		if (
			isset($ratio['CRM_DYNAMIC_TYPES'][$newDynamicEntityTypeIdRatioKey])
			&& $ratio['CRM_DYNAMIC_TYPES'][$newDynamicEntityTypeIdRatioKey] > 0
		)
		{
			$entityTypeId = (int)$ratio['CRM_DYNAMIC_TYPES'][$newDynamicEntityTypeIdRatioKey];
			if (CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
			{
				$newDynamicEntityTypeId = $entityTypeId;
			}
		}

		return $newDynamicEntityTypeId;
	}

	public function getNewDynamicTypeCategoryIdByRatio(
		int $oldDynamicEntityTypeId,
		int $oldCategoryId,
		array $ratio
	): int
	{
		$newCategoryId = 0;

		$ratioKey = $this->getDynamicCategoryCode($oldDynamicEntityTypeId, $oldCategoryId);
		if (isset($ratio['CRM_STATUS'][$ratioKey]) && $ratio['CRM_STATUS'][$ratioKey] > 0)
		{
			$newCategoryId = (int)$ratio['CRM_STATUS'][$ratioKey];
		}

		return $newCategoryId;
	}

	public function prepareDynamicTypeReplacementLists(array $dynamicTypesInfo, array $ratioInfo): array
	{
		$result = [
			'from' => [],
			'to' => [],
		];

		foreach ($dynamicTypesInfo as $oldDynamicTypeId => $dynamicTypeInfo)
		{
			$oldDynamicTypeId = (int)$oldDynamicTypeId;
			$oldDynamicEntityTypeId = (int)($dynamicTypeInfo['entityTypeId'] ?? 0);
			if (
				$oldDynamicEntityTypeId > 0
				&& isset($ratioInfo['CRM_DYNAMIC_TYPES'])
			)
			{
				$newDynamicTypeIdRatioKey = "DT$oldDynamicTypeId";
				$newEntityTypeIdRatioKey = CCrmOwnerType::DynamicTypePrefixName . $oldDynamicEntityTypeId;
				if (
					isset($ratioInfo['CRM_DYNAMIC_TYPES'][$newDynamicTypeIdRatioKey])
					&& $ratioInfo['CRM_DYNAMIC_TYPES'][$newDynamicTypeIdRatioKey] > 0
					&& isset($ratioInfo['CRM_DYNAMIC_TYPES'][$newEntityTypeIdRatioKey])
					&& $ratioInfo['CRM_DYNAMIC_TYPES'][$newEntityTypeIdRatioKey] > 0
				)
				{
					$newDynamicTypeId = (int)$ratioInfo['CRM_DYNAMIC_TYPES'][$newDynamicTypeIdRatioKey];
					$newDynamicEntityTypeId = (int)$ratioInfo['CRM_DYNAMIC_TYPES'][$newEntityTypeIdRatioKey];

					// /DYNAMIC_\d+/
					$result['from'][] = CCrmOwnerType::DynamicTypePrefixName . $oldDynamicEntityTypeId;
					$result['to'][] = CCrmOwnerType::DynamicTypePrefixName . $newDynamicEntityTypeId;

					if (
						isset($dynamicTypeInfo['categoryIds'])
						&& is_array($dynamicTypeInfo['categoryIds'])
					)
					{
						foreach ($dynamicTypeInfo['categoryIds'] as $oldCategoryId)
						{
							$oldCategoryId = (int)$oldCategoryId;
							$catgoryRatioKey = $this->getDynamicCategoryCode($oldDynamicEntityTypeId, $oldCategoryId);
							if (
								isset($ratioInfo['CRM_STATUS'][$catgoryRatioKey])
								&& $ratioInfo['CRM_STATUS'][$catgoryRatioKey] > 0
							)
							{
								$newCategoryId = (int)$ratioInfo['CRM_STATUS'][$catgoryRatioKey];

								// /DT\d+_\d+/
								$result['from'][] = $catgoryRatioKey;
								$result['to'][] = $this->getDynamicCategoryCode(
									$newDynamicEntityTypeId,
									$newCategoryId
								);
							}
						}
					}

					$matches = [];
					if (
						isset($dynamicTypeInfo['userFieldEntityId'])
						&& is_string($dynamicTypeInfo['userFieldEntityId'])
						&& isset($dynamicTypeInfo['userFieldNames'])
						&& is_array($dynamicTypeInfo['userFieldNames'])
						&& !empty($dynamicTypeInfo['userFieldNames'])
						&& preg_match('/CRM_(\d+)/u', $dynamicTypeInfo['userFieldEntityId'], $matches)
					)
					{
						$oldDynamicTypeId = $matches[1];
						$oldUserFieldPrefix = "UF_CRM_{$oldDynamicTypeId}_";
						$oldUserFieldPrefixLength = strlen($oldUserFieldPrefix);
						$newUserFieldPrefix = "UF_CRM_{$newDynamicTypeId}_";
						foreach($dynamicTypeInfo['userFieldNames'] as $oldUserFieldName)
						{
							if (substr($oldUserFieldName, 0, $oldUserFieldPrefixLength) === $oldUserFieldPrefix)
							{
								$newUserFieldName =
									$newUserFieldPrefix . substr($oldUserFieldName, $oldUserFieldPrefixLength)
								;

								// /UF_CRM_\d+_/
								$result['from'][] = $oldUserFieldName;
								$result['to'][] = $newUserFieldName;
							}
						}
					}
				}
			}
		}

		return $result;
	}

	public static function getDynamicTypeCustomSectionIdByEntityTypeId(int $entityTypeId): int
	{
		$result = 0;

		if (CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			$type = Container::getInstance()->getTypeByEntityTypeId($entityTypeId);
			if ($type)
			{
				$customSectionId = $type->getCustomSectionId();
				if ($customSectionId > 0)
				{
					$result = $customSectionId;
				}
			}
		}

		return $result;
	}

	public static function checkDynamicTypeByEntityType(int $entityTypeId): bool
	{
		$type = Container::getInstance()->getTypeByEntityTypeId($entityTypeId);
		if ($type)
		{
			return true;
		}

		return false;
	}
}
