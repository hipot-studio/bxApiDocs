<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\CompanyStructure;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\HumanResources\Util\StructureHelper;

/**
 * Get total count of departments and teams.
 *
 * @see \Bitrix\HumanResources\Rest\Controller\Node::countAction — REST analog
 */
class GetTotalNodeCountTool extends NodeBaseTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW;

	public function getName(): string
	{
		return 'get_total_node_count';
	}

	public function getDescription(): string
	{
		return 'Get the total count of departments and teams visible to the current user — returns both numbers in a single call. Prefer this tool for any "how many departments", "how many teams", "how many nodes" question; do not enumerate via department_list or team_list. No parameters required. Results are filtered by user permissions.';
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

			$nodeRepository = InternalContainer::getNodeRepository();

			$deptCount = $nodeRepository->countAll(
				structureId: $structure->id,
				structureAction: StructureAction::ViewAction,
				viewerUserId: $userId,
			);

			$teamCount = $nodeRepository->countAll(
				nodeTypes: [NodeEntityType::TEAM],
				structureId: $structure->id,
				structureAction: StructureAction::ViewAction,
				viewerUserId: $userId,
			);

			return "Visible to current user: {$deptCount} departments, {$teamCount} teams.";
		}
		catch (\Exception $e)
		{
			$this->logException('Error getting node count: ' . $e->getMessage());

			return 'Error getting node count.';
		}
	}
}
