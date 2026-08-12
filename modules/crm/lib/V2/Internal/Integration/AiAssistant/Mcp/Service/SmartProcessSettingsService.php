<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service;

use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;

/**
 * Shared logic for MCP tools that change settings of an existing smart process.
 *
 * Centralizes the prologue that every settings tool repeats: resolve the smart
 * process by ENTITY_TYPE_ID and verify that the current user is allowed to change
 * its settings.
 */
final class SmartProcessSettingsService
{
	/**
	 * Resolves a smart process by ENTITY_TYPE_ID and verifies that the user may
	 * change its settings: the process exists, the user has the update permission
	 * and changing settings is not blocked by the tariff plan.
	 *
	 * @return Type|ToolResult resolved type on success, or a failed ToolResult
	 *     describing the problem (not found / access denied / tariff restricted).
	 */
	public function resolveUpdatableType(int $entityTypeId, int $userId): Type|ToolResult
	{
		$type = Container::getInstance()->getTypeByEntityTypeId($entityTypeId);
		if ($type === null)
		{
			return ToolResult::fail(
				"Smart process with ENTITY_TYPE_ID={$entityTypeId} was not found.",
			);
		}

		$canUpdate = Container::getInstance()
			->getUserPermissions($userId)
			->dynamicType()
			->canUpdate($entityTypeId)
		;
		if (!$canUpdate)
		{
			return ToolResult::fail(
				"Access denied: you do not have permission to manage smart process"
					. " with ENTITY_TYPE_ID={$entityTypeId}.",
			);
		}

		$restriction = RestrictionManager::getDynamicTypesLimitRestriction();
		if ($restriction->isTypeSettingsRestricted($entityTypeId))
		{
			return ToolResult::fail($restriction->getUpdateTypeRestrictedError()->getMessage());
		}

		return $type;
	}
}
