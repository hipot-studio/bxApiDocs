<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Node;

use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Schema\InputProperty;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\HumanResources\Util\StructureHelper;

/**
 * Search nodes by name.
 *
 * @see \Bitrix\HumanResources\Rest\Controller\Node::searchAction — REST analog
 * @see \Bitrix\HumanResources\Integration\UI\DepartmentProvider::doSearch — entity selector search
 */
abstract class NodeSearchTool extends NodeBaseTool
{
	private const DEFAULT_LIMIT = 10;
	private const MAX_LIMIT = 50;

	public function getInputSchema(): array
	{
		$entity = $this->type === NodeEntityType::TEAM ? 'team' : 'department';

		return [
			'type' => 'object',
			'properties' => [
				'name' => [
					'description' => "Search query: full or partial name of the {$entity}",
					'type' => 'string',
					'minLength' => 1,
				],
				'parentId' => InputProperty::nodeId('Optional: limit search to children of this node'),
				'limit' => InputProperty::paginationLimit(self::DEFAULT_LIMIT, self::MAX_LIMIT),
			],
			'additionalProperties' => false,
			'required' => ['name'],
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$name = $args['name'] ?? '';
		$parentIds = isset($args['parentId']) ? [(int)$args['parentId']] : null;
		$limit = (int)($args['limit'] ?? self::DEFAULT_LIMIT);

		if ($limit <= 0)
		{
			$limit = self::DEFAULT_LIMIT;
		}
		elseif ($limit > self::MAX_LIMIT)
		{
			$limit = self::MAX_LIMIT;
		}

		try
		{
			$nodes = InternalContainer::getNodeRepository()->findAllByName(
				name: $name,
				strict: false,
				parentIds: $parentIds,
				nodeTypes: [$this->type],
				structureAction: StructureAction::ViewAction,
				limit: $limit,
				userId: $userId,
			);

			if ($nodes->count() === 0)
			{
				return "No {$this->type->value} nodes found matching \"{$name}\".";
			}

			$result = [];
			foreach ($nodes as $node)
			{
				$result[] = StructureHelper::getNodeInfo($node);
			}

			$typeName = $this->type === NodeEntityType::DEPARTMENT ? 'departments' : 'teams';

			return "Found {$nodes->count()} {$typeName} matching \"{$name}\":\n" .
				implode(
					"\n",
					array_map(
						fn($node) => "Id: {$node['id']}, Name: {$node['name']}, Parent: {$node['parentId']}, Employees: {$node['userCount']}",
						$result,
					),
				) . '.'
			;
		}
		catch (\Exception $e)
		{
			$this->logException('Error searching nodes: ' . $e->getMessage());

			return 'Error searching nodes.';
		}
	}
}
