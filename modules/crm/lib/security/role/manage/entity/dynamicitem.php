<?php

namespace Bitrix\Crm\Security\Role\Manage\Entity;

use Bitrix\Crm\Category\PermissionEntityTypeHelper;
use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\Security\Role\Manage\DTO\EntityDTO;
use Bitrix\Crm\Security\Role\Manage\PermissionAttrPresets;
use Bitrix\Crm\Service;

class DynamicItem implements PermissionEntity, FilterableByTypes, FilterableByCategory
{
	use Trait\FilterableByCategory;
	use Trait\FilterableByTypes;

	private function permissions(
		bool $isAutomationEnabled,
		bool $isStagesEnabled,
		bool $isObserversEnabled,
		bool $isObserversWriteByStages,
		array $stages,
		?string $inheritDescription,
	): array
	{
		$permissions = $isAutomationEnabled
			? PermissionAttrPresets::crmEntityPresetAutomation(
				true,
				$inheritDescription,
				withObserversWrite: $isObserversEnabled,
				withObserversWriteByStages: $isObserversWriteByStages,
			)
			: PermissionAttrPresets::crmEntityPreset(
				$inheritDescription,
				$isObserversEnabled,
				$isObserversWriteByStages,
			)
		;

		$permissions = array_merge(
			$permissions,
			PermissionAttrPresets::crmEntityKanbanHideSum()
		);

		if ($isStagesEnabled)
		{
			$permissions = array_merge(
				$permissions,
				PermissionAttrPresets::crmStageTransition($stages, $inheritDescription),
			);
		}

		return $permissions;
	}

	/**
	 * @return EntityDTO[]
	 */
	public function make(): array
	{
		$typesMap = Service\Container::getInstance()->getDynamicTypesMap()->load();
		$types = $this->filterTypes($typesMap->getTypes());

		$result = [];
		foreach ($types as $type)
		{
			$entityTypeId = $type->getEntityTypeId();
			$isAutomationEnabled = $typesMap->isAutomationEnabled($entityTypeId);
			$isStagesEnabled = $typesMap->isStagesEnabled($entityTypeId);
			$isCategoriesEnabled = $typesMap->isCategoriesEnabled($entityTypeId);
			$isObserversEnabled = (bool)$type->getIsObserversEnabled();
			$isStagesType = $type->getIsStagesEnabled();
			// the variant control belongs only to a block that really gets the stage sub-rows below
			$isObserversWriteByStages = $isObserversEnabled && $isStagesType;
			$stagesFieldName = $typesMap->getStagesFieldName($entityTypeId);

			$categories = $this->filterItemCategories($typesMap->getCategories($entityTypeId));

			foreach ($categories as $category)
			{
				$entityName = (new PermissionEntityTypeHelper($entityTypeId))
					->getPermissionEntityTypeForCategory($category->getId())
				;

				$funnelName = $isCategoriesEnabled ? $category->getName() : null;

				$fields = [];
				$stages = [];
				if ($isStagesType)
				{
					foreach ($typesMap->getStages($entityTypeId, $category->getId()) as $stage)
					{
						$stages[$stage->getStatusId()] = $stage->getName();
					}

					$fields = [$stagesFieldName => $stages];
				}

				$inheritDescription = PermissionAttrPresets::stageInheritDescription(
					$entityTypeId,
					$type->getTitle(),
					// funnel is reflected in the stage-inheritance phrase only for stage-enabled types
					$isStagesType ? $funnelName : null,
				);

				$perms = $this->permissions(
					$isAutomationEnabled,
					$isStagesEnabled,
					$isObserversEnabled,
					$isObserversWriteByStages,
					$stages,
					$inheritDescription,
				);

				$result[] = new EntityDTO(
					$entityName,
					$type->getTitle(),
					$fields,
					$perms,
					$funnelName,
					'smart-process',
					'--ui-color-accent-light-blue',
				);
			}
		}

		return $result;
	}

	/**
	 * @param Type[] $types
	 * @return Type[]
	 */
	protected function filterTypes(array $types): array
	{
		if ($this->excludeEntityTypeIds !== null)
		{
			$isExclude = fn (Type $type)
				=> !in_array($type->getEntityTypeId(), $this->excludeEntityTypeIds, true)
			;

			$types = array_filter($types, $isExclude);
		}

		if ($this->filterByEntityTypeIds !== null)
		{
			$isRemain = fn (Type $type)
				=> in_array($type->getEntityTypeId(), $this->filterByEntityTypeIds, true)
			;

			$types = array_filter($types, $isRemain);
		}

		return $types;
	}
}
