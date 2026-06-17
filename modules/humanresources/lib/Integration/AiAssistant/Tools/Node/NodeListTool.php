<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Node;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\SelectionCondition\Node\NodeAccessFilter;
use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\HumanResources\Builder\Structure\Sort\NodeSort;
use Bitrix\HumanResources\Enum\SortDirection;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Schema\InputProperty;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\HumanResources\Util\StructureHelper;

/**
 * List nodes by type.
 *
 * @see \Bitrix\HumanResources\Rest\Controller\Node::listAction — REST analog
 */
abstract class NodeListTool extends NodeBaseTool
{
	private const DEFAULT_LIMIT = 50;
	private const MAX_LIMIT = 200;

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'limit' => InputProperty::paginationLimit(self::DEFAULT_LIMIT, self::MAX_LIMIT),
				'offset' => InputProperty::paginationOffset(),
			],
			'additionalProperties' => false,
			'required' => [],
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$limit = min((int)($args['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);
		if ($limit <= 0)
		{
			$limit = self::DEFAULT_LIMIT;
		}
		$offset = max((int)($args['offset'] ?? 0), 0);

		try
		{
			$defaultStructure = StructureHelper::getDefaultStructure();
			if ($defaultStructure === null)
			{
				return 'No default structure found.';
			}

			$nodes = (new NodeDataBuilder())
				->addFilter(
					new NodeFilter(
						entityTypeFilter: NodeTypeFilter::fromNodeType($this->type),
						structureId: $defaultStructure->id,
						active: true,
						accessFilter: new NodeAccessFilter(StructureAction::ViewAction, $userId),
					),
				)
				->setSort(new NodeSort(sort: SortDirection::Asc))
				->setLimit($limit)
				->setOffset($offset)
				->getAll()
			;

			if ($nodes->count() === 0)
			{
				$typeLabel = $this->type->value;

				return "No {$typeLabel} nodes found (offset: {$offset}).";
			}

			$result = [];
			foreach ($nodes as $node)
			{
				$info = StructureHelper::getNodeInfo($node);
				$result[] = "Id: {$info['id']}, Name: {$info['name']}, ParentId: {$info['parentId']}, Employees: {$info['userCount']}";
			}

			$total = $nodes->count();
			$hasMore = $total === $limit ? ' There may be more results — increase offset to continue.' : '';

			return "Found {$total} nodes (offset: {$offset}):\n"
				. implode("\n", $result)
				. ".{$hasMore}"
			;
		}
		catch (\Exception $e)
		{
			$this->logException('Error listing nodes: ' . $e->getMessage());

			return 'Error listing nodes.';
		}
	}
}
