<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Node;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Util\StructureHelper;

abstract class NodeShowTool extends NodeBaseTool
{
	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'nodeId' => [
					'description' => 'Identifier of the node to get information about',
					'type' => 'number',
				],
			],
			'additionalProperties' => false,
			'required' => ['nodeId'],
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$item = NodeModel::createFromId((int)$args['nodeId']);

		$actionId = $this->type === NodeEntityType::DEPARTMENT
			? StructureActionDictionary::ACTION_STRUCTURE_VIEW
			: StructureActionDictionary::ACTION_TEAM_VIEW
		;
		if (!$this->checkAccess($userId, $actionId, $item))
		{
			return 'Access denied';
		}

		$node = $item->getNode();

		try
		{
			$nodeInfo = StructureHelper::getNodeInfo($node);
			$employees = Container::getNodeMemberRepository()->findAllByNodeId(
				$node->id,
			);
			$roles = Container::getRoleRepository()->findByIds(
				array_map(fn($member) => $member->roles[0] ?? null, $employees->getValues()),
			);

			$membersInfo = [];
			$employeeUserCollection = Container::getUserService()->getUserCollectionFromMemberCollection($employees);
			$rolesById = [];
			foreach ($roles->getValues() as $role) {
				$rolesById[$role->id] = $role->xmlId;
			}
			foreach ($employeeUserCollection as $user) {
				$userId = $user->id;
				// Find corresponding employee by entityId
				$employee = null;
				foreach ($employees->getValues() as $emp) {
					if ($emp->entityId === $userId) {
						$employee = $emp;
						break;
					}
				}
				$info = Container::getUserService()->getBaseInformation($user);
				$roleId = $employee->roles[0] ?? null;
				$info['role'] = $roleId && isset($rolesById[$roleId]) ? $rolesById[$roleId] : null;
				$membersInfo[] = $info;
			}

			return "The node with id {$nodeInfo['id']} is named \"{$nodeInfo['name']}\", has a parent {$nodeInfo['parentId']} and a description {$nodeInfo['description']}. It has {$nodeInfo['usersCount']} members: " .
				implode(
					', ',
					array_map(
						fn($member) => $member['name'] . ' (' . $member['role'] . ($member['workPosition'] ? ', ' . $member['workPosition'] . ')' : ')'),
						$membersInfo,
					),
				) . '.'
			;
		}
		catch (\Exception $e)
		{
			$this->logException('Error getting info: ' . $e->getMessage());

			return 'Error getting info.';
		}
	}
}
