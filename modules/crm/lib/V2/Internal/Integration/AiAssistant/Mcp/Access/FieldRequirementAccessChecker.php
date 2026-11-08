<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Access;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Loader;
use Bitrix\Rest\V3\Mcp\Access\MethodAccessCheckerInterface;
use Bitrix\Rest\V3\Schema\MethodDescription;

Loader::requireModule('rest');

final class FieldRequirementAccessChecker implements MethodAccessCheckerInterface
{
	public function canAccess(MethodDescription $methodDescription, int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$entityTypeId = $this->getRouteEntityTypeId($methodDescription);
		if ($entityTypeId === null)
		{
			return false;
		}

		if (EntityType::isPossibleSmartProcessTypeId($entityTypeId))
		{
			if (Container::getInstance()->getTypeByEntityTypeId($entityTypeId) === null)
			{
				return false;
			}
		}
		elseif (!in_array($entityTypeId, [
			OwnerType::DEAL,
			OwnerType::LEAD,
			OwnerType::CONTACT,
			OwnerType::COMPANY,
			OwnerType::QUOTE,
			OwnerType::SMART_INVOICE,
		], true))
		{
			return false;
		}

		return Container::getInstance()
			->getUserPermissions($userId)
			->configuration()
			->canManageFields(
				ConfigurationCallSite::RequirementProvider,
				ConfigurationScope::ofEntityType($entityTypeId),
			);
	}

	private function getRouteEntityTypeId(MethodDescription $methodDescription): ?int
	{
		$value = $methodDescription->queryParams['entityTypeId'] ?? null;
		if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1)
		{
			$value = (int)$value;
		}

		return is_int($value) && $value > 0 ? $value : null;
	}
}
