<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Department;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Type\NodeEntityType;

/**
 * Get subordinates count by departments for a given user.
 *
 * @see \Bitrix\HumanResources\Rest\Controller\Employee::subordinatesAction — REST analog
 */
class GetSubordinatesCountTool extends NodeBaseTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW;

	public function getName(): string
	{
		return 'get_subordinates_count';
	}

	public function getDescription(): string
	{
		return 'Get the number of subordinates for a specific user. Shows how many employees are in departments where the user is a head or deputy head, including all sub-departments. Requires a userId parameter.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'targetUserId' => [
					'description' => 'User ID to get subordinates count for',
					'type' => 'integer',
					'minimum' => 1,
				],
			],
			'additionalProperties' => false,
			'required' => ['targetUserId'],
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$targetUserId = (int)($args['targetUserId'] ?? 0);
		if ($targetUserId <= 0)
		{
			return 'Invalid userId.';
		}

		try
		{
			$departments = InternalContainer::getNodeMemberService()->getSubordinatesCountByUser($targetUserId, $userId);

			if (empty($departments))
			{
				return "User {$targetUserId} is not a head or deputy head of any department.";
			}

			$lines = [];
			foreach ($departments as $dept)
			{
				$lines[] = "{$dept['name']} (id:{$dept['nodeId']}, role:{$dept['role']}): {$dept['subordinatesCount']} subordinates";
			}

			return "User {$targetUserId} manages " . count($departments) . " department(s):\n"
				. implode("\n", $lines) . '.'
			;
		}
		catch (\Exception $e)
		{
			$this->logException('Error getting subordinates count: ' . $e->getMessage());

			return 'Error getting subordinates count.';
		}
	}
}
