<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\CompanyStructure;

use Bitrix\HumanResources\Access\Model\UserModel;
use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Access\Permission\PermissionVariablesDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Schema\InputProperty;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Internals\Service\Structure\UserService;
use Bitrix\HumanResources\Item\User;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\StructureAction;

/**
 * Search employees by name.
 *
 * Two modes:
 *  - default (includeStructure=false): returns base info only (id, name, position).
 *  - extended (includeStructure=true): also returns departments and teams.
 *    Falls back to base info with a warning when the caller has no structure view permission.
 *
 * @see \Bitrix\HumanResources\Rest\Controller\Employee::searchAction — REST analog
 */
class SearchEmployeeTool extends NodeBaseTool
{
	public function canList(int $userId): bool
	{
		return true;
	}

	public function canRun(int $userId): bool
	{
		return true;
	}

	public function getName(): string
	{
		return 'search_employee';
	}

	public function getDescription(): string
	{
		return 'Search for an employee by name across the entire company. Provide different name spellings, diminutive forms, or transliterations. By default returns employee IDs, names, and positions. Pass includeStructure=true to also return the departments and teams the employee belongs to (requires structure view permission; if the caller lacks it, base info is returned together with a notice that structure data could not be retrieved).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'searchQueries' => [
					'type' => 'array',
					'items' => [
						'type' => 'string',
						'description' => 'A single name variation for the search.',
						'minLength' => 2,
					],
					'description' =>
						'A list of name variations for the employee you are trying to find. '
						. 'Provide different spellings, diminutive forms, or transliterations.',
					'minItems' => 1,
				],
				'nodeId' => InputProperty::nodeId('Optional: limit search to a specific node'),
				'includeStructure' => [
					'type' => 'boolean',
					'description' =>
						'When true, also include departments and teams for each employee. '
						. 'Requires structure view permission; if the caller lacks it, base info '
						. 'is still returned with a notice that structure data could not be retrieved. '
						. 'Defaults to false.',
					'default' => false,
				],
			],
			'additionalProperties' => false,
			'required' => ['searchQueries'],
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$searchQueries = $args['searchQueries'] ?? [];
		$nodeId = isset($args['nodeId']) ? (int)$args['nodeId'] : null;
		$includeStructure = (bool)($args['includeStructure'] ?? false);

		if (empty($searchQueries))
		{
			return 'Search query is required.';
		}

		if ($nodeId !== null)
		{
			$node = InternalContainer::getNodeRepository()->getById(
				$nodeId,
				StructureAction::ViewAction,
				$userId,
			);
			if ($node === null)
			{
				return 'Node not found or access denied.';
			}
		}

		try
		{
			$searchService = InternalContainer::getUserService();
			$allUsers = [];

			foreach ($searchQueries as $query)
			{
				foreach ($searchService->searchByName($query, $nodeId) as $user)
				{
					$allUsers[$user->id] = $user;
				}
			}

			if (empty($allUsers))
			{
				return 'No employees found matching the search queries.';
			}

			if (!$includeStructure)
			{
				return $this->formatBaseResults($allUsers);
			}

			if (!$this->hasStructureViewPermission($userId))
			{
				return $this->formatBaseResults($allUsers)
					. "\nStructure data could not be returned: caller has no permission to view the company structure.";
			}

			return $this->formatExtendedResults($allUsers, $searchService, $userId);
		}
		catch (\Exception $e)
		{
			$this->logException('Error searching employees: ' . $e->getMessage());

			return 'Error searching employees.';
		}
	}

	private function hasStructureViewPermission(int $userId): bool
	{
		$user = UserModel::createFromId($userId);
		if ($user->isAdmin())
		{
			return true;
		}

		return $user->getPermission(PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW)
			!== PermissionVariablesDictionary::VARIABLE_NONE;
	}

	/**
	 * @param User[] $allUsers
	 */
	private function formatBaseResults(array $allUsers): string
	{
		$userService = Container::getUserService();
		$lines = [];

		foreach ($allUsers as $user)
		{
			$info = $userService->getBaseInformation($user);
			$lines[] = "Id: {$user->id}, Name: {$info['name']}, Position: {$info['workPosition']}";
		}

		$count = count($lines);

		return "Found {$count} employees:\n" . implode("\n", $lines) . '.';
	}

	/**
	 * @param User[] $allUsers
	 */
	private function formatExtendedResults(
		array $allUsers,
		UserService $searchService,
		int $userId,
	): string
	{
		$userService = Container::getUserService();
		$nodesByUsers = $searchService->getNodesByUsers($allUsers, $userId);
		$lines = [];

		foreach ($allUsers as $user)
		{
			$info = $userService->getBaseInformation($user);
			$nodes = $nodesByUsers[$user->id] ?? ['departments' => [], 'teams' => []];

			$deptNames = array_map(
				fn($d) => $d['name'] . ' (id:' . $d['id'] . ')',
				$nodes['departments'],
			);
			$teamNames = array_map(
				fn($t) => $t['name'] . ' (id:' . $t['id'] . ')',
				$nodes['teams'],
			);

			$deptStr = empty($deptNames) ? 'none' : implode(', ', $deptNames);
			$teamStr = empty($teamNames) ? 'none' : implode(', ', $teamNames);

			$lines[] = "Id: {$user->id}, Name: {$info['name']}, Position: {$info['workPosition']}, Departments: {$deptStr}, Teams: {$teamStr}";
		}

		$count = count($lines);

		return "Found {$count} employees:\n" . implode("\n", $lines) . '.';
	}
}
