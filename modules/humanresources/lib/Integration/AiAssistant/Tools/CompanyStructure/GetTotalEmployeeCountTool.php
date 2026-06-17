<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\CompanyStructure;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Util\StructureHelper;

/**
 * Get total employee count across the structure.
 *
 * @see \Bitrix\HumanResources\Rest\Controller\Employee::countAction — REST analog
 */
class GetTotalEmployeeCountTool extends NodeBaseTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW;

	public function getName(): string
	{
		return 'get_total_employee_count';
	}

	public function getDescription(): string
	{
		return 'Get total number of unique employees in the entire company structure. No parameters required.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => (object)[],
			'additionalProperties' => false,
		];
	}

	public function execute(int $userId, ...$args): string
	{
		try
		{
			$structure = StructureHelper::getDefaultStructure();
			if ($structure === null)
			{
				return 'Default structure not found.';
			}

			$rootNode = InternalContainer::getNodeRepository()->getRootNodeByStructureId($structure->id);
			if ($rootNode === null)
			{
				return 'Root node not found.';
			}

			$count = InternalContainer::getNodeMemberRepository()->countUniqueUsersByNodeIdWithSubNodes($rootNode->id);

			return "Total employees in the company: {$count}.";
		}
		catch (\Exception $e)
		{
			$this->logException('Error getting employee count: ' . $e->getMessage());

			return 'Error getting employee count.';
		}
	}
}
