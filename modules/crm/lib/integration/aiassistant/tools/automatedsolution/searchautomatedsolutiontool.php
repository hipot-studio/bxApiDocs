<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\AutomatedSolution;

use Bitrix\Crm\Integration\AiAssistant\Tools\BaseCrmTool;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Web\Json;

final class SearchAutomatedSolutionTool extends BaseCrmTool
{
	private const SEARCH_LIMIT = 50;

	public function getName(): string
	{
		return 'search_automated_solution';
	}

	public function getDescription(): string
	{
		return 'Searches for an automated solution by name substring. '
			. 'Returns the automated solution with the highest ID if multiple matches are found. '
			. 'Only returns automated solutions where the current user is an admin.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'title' => [
					'description' => 'Name or beginning of the name of the automated solution to search for',
					'type' => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				],
			],
			'additionalProperties' => false,
			'required' => ['title'],
		];
	}

	protected function executeTool(int $userId, ...$args): string
	{
		$title = trim((string)($args['title'] ?? ''));
		if ($title === '')
		{
			return Json::encode(['error' => 'Title is required and cannot be empty']);
		}

		$container = Container::getInstance();
		$userPermissions = $container->getUserPermissions($userId);
		$escapedTitle = str_replace(['%', '_'], ['\\%', '\\_'], $title);

		$solutions = $container->getAutomatedSolutionManager()->findAutomatedSolutions(
			filter: ['%TITLE' => $escapedTitle],
			order: ['ID' => 'DESC'],
			limit: self::SEARCH_LIMIT,
		);

		foreach ($solutions as $solution)
		{
			$id = (int)$solution['ID'];
			if ($userPermissions->automatedSolution()->isAutomatedSolutionAdmin($id))
			{
				return Json::encode([
					'id' => $id,
					'title' => $solution['TITLE'],
				]);
			}
		}

		return Json::encode([
			'error' => 'NotFound',
			'message' => "No automated solution found matching '{$title}' where you have admin permissions",
		]);
	}
}
