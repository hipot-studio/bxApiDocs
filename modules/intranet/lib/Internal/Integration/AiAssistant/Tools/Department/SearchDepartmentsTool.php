<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Department;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Department\SearchDepartmentsDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\BaseTool;
use Bitrix\Intranet\Internal\Integration\Humanresources\DepartmentRepository;

class SearchDepartmentsTool extends BaseTool
{
	public function canRun(int $userId): bool
	{
		return true;
	}

	public function getName(): string
	{
		return 'search_departments';
	}

	public function getDescription(): string
	{
		return
			'Searches departments available for invitation for the current user. '
			. 'Use this tool to resolve a department name before an invitation or invite-link action. '
			. 'Supports pagination via limit and offset. '
			. 'If several departments match, ask the user to choose before continuing. '
			. 'If hasMore is true, call the tool again with a larger offset. '
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'departmentName' => [
					'type' => 'string',
					'description' => 'Department name or a part of it. If omitted, returns available departments.',
				],
				'limit' => [
					'type' => 'integer',
					'minimum'=> 1,
					'maximum'=> 50,
					'description' => 'Maximum number of departments to return. Positive integer from 1 to 50. Default is 20.',
				],
				'offset' => [
					'type' => 'integer',
					'minimum'=> 0,
					'description' => 'Offset for pagination. Non-negative integer. Default is 0.',
				],
			],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		try
		{
			$dto = SearchDepartmentsDto::fromArray($args);
			$departmentRepository = new DepartmentRepository();

			$departments = $departmentRepository->searchAvailableDepartmentsByName(
				$userId,
				$dto->departmentName,
				$dto->limit,
				$dto->offset,
			);

			$totalCount = $departmentRepository->countAvailableDepartmentsByName(
				$userId,
				$dto->departmentName,
			);
		}
		catch (McpException $e)
		{
			throw $e;
		}
		catch (\Throwable $e)
		{
			throw new McpException($e->getMessage());
		}

		return [
			'departments' => $departments->map(static function ($department)
				{
					return [
						'id' => $department->getId(),
						'name' => $department->getName(),
					];
				}
			),
			'limit' => $dto->limit,
			'offset' => $dto->offset,
			'total' => $totalCount,
			'hasMore' => ($dto->offset + $dto->limit) < $totalCount,
		];
	}
}
