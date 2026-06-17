<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Node;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Schema\InputProperty;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;

/**
 * Remove employees from a node.
 *
 * @see \Bitrix\HumanResources\Rest\Controller\Node\Member::removeAction — REST analog
 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member::deleteUserAction — ajax controller
 */
abstract class NodeRemoveEmployeeTool extends NodeBaseTool
{
	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'nodeId' => InputProperty::nodeId('Identifier of the node to remove employees from'),
				'userIds' => InputProperty::userIdList('User IDs to remove from the node') + ['minItems' => 1],
			],
			'additionalProperties' => false,
			'required' => ['nodeId', 'userIds'],
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$item = NodeModel::createFromId((int)$args['nodeId']);

		$actionId = $this->type === NodeEntityType::DEPARTMENT
			? StructureActionDictionary::ACTION_EMPLOYEE_REMOVE_FROM_DEPARTMENT
			: StructureActionDictionary::ACTION_TEAM_MEMBER_REMOVE
		;
		if (!$this->checkAccess($userId, $actionId, $item))
		{
			return 'Access denied';
		}

		$nodeId = (int)$args['nodeId'];
		$userIds = $args['userIds'] ?? [];

		$data = Container::getNodeMemberService()->removeFromNodeByUserIds($nodeId, $userIds);

		$result = '';
		if (!empty($data['removed']))
		{
			$result .= 'Removed users: ' . implode(', ', $data['removed']) . '.';
		}
		if (!empty($data['failed']))
		{
			$failedLines = array_map(
				fn($f) => "User {$f['userId']}: {$f['reason']}",
				$data['failed'],
			);
			$result .= ($result ? "\n" : '') . 'Failed: ' . implode('; ', $failedLines) . '.';
		}

		return $result ?: 'No users processed.';
	}
}
