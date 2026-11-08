<?php

namespace Bitrix\Crm\Service\UserPermissions;

use Bitrix\Crm\AutomatedSolution\CapabilityAccessChecker;

/**
 * @internal
 * Do not use directly, only through \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->dynamicType()
 */

final class DynamicType
{
	public function __construct(
		private readonly \Bitrix\Crm\Service\UserPermissions\EntityPermissions\Admin $entityAdmin,
		private readonly Configuration $configuration,
	)
	{
	}

	/**
	 * Can user add a dynamic type (smart-process)
	 *
	 * The right of the contour the type is created in ({@see Configuration::canManageDynamicTypes()}),
	 * `null` and `0` alike being CRM. A locked automated solution stays forbidden whatever the right
	 * says - that ban is a product condition of this surface and not part of the capability, and it is
	 * asked about every identifier the contour is read from.
	 *
	 * @param int|null $automatedSolutionId
	 * @return bool
	 */
	public function canAdd(?int $automatedSolutionId = null): bool
	{
		$canManageTypes = $this->configuration->canManageDynamicTypes(
			ConfigurationCallSite::DynamicTypePermissions,
			ConfigurationScope::ofDynamicTypeTarget($automatedSolutionId),
		);
		if (!$canManageTypes)
		{
			return false;
		}

		if ($automatedSolutionId)
		{
			return !CapabilityAccessChecker::getInstance()->isLockedAutomatedSolution($automatedSolutionId);
		}

		return true;
	}

	/**
	 * Can user update a dynamic type (smart-process)
	 *
	 * The right of the contour the type belongs to ({@see Configuration::canManageDynamicTypes()}). The
	 * bans of a type based static entity and of a locked type are product conditions of this surface and
	 * are asked before the right, exactly as they used to be.
	 *
	 * @param int $entityTypeId
	 * @return bool
	 */
	public function canUpdate(int $entityTypeId): bool
	{
		if (\CCrmOwnerType::isDynamicTypeBasedStaticEntity($entityTypeId))
		{
			return false;
		}

		if (CapabilityAccessChecker::getInstance()->isLockedEntityType($entityTypeId))
		{
			return false;
		}

		$scope = ConfigurationScope::ofEntityType($entityTypeId);
		if ($scope->isMissingDynamicType())
		{
			// A type the container does not hold is refused by the facade, while this surface used to
			// answer for the CRM contour. No live caller reaches here - every one of them resolves the type
			// first - so the former answer is kept rather than tightened by accident.
			return $this->entityAdmin->isAdminForEntity($entityTypeId);
		}

		return $this->configuration->canManageDynamicTypes(
			ConfigurationCallSite::DynamicTypePermissions,
			$scope,
		);
	}
}
