<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\AutomatedSolution;

use Bitrix\Crm\Integration\AiAssistant\Tools\BaseCrmTool;
use Bitrix\Crm\Integration\IntranetManager;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Web\Json;

final class CreateAutomatedSolutionTool extends BaseCrmTool
{
	public function getName(): string
	{
		return 'create_automated_solution';
	}

	public function getDescription(): string
	{
		return 'Creates a new automated solution in CRM. '
			. 'Returns the ID of the created automated solution. '
			. 'This does NOT create dynamic types inside it — use create_dynamic_type for that.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'title' => [
					'description' => 'Name of the automated solution to create',
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

		if (!IntranetManager::isCustomSectionsAvailable())
		{
			return Json::encode([
				'error' => 'CustomSectionsUnavailable',
				'message' => 'Custom sections are not available on this portal, automated solutions cannot be created',
			]);
		}

		$container = Container::getInstance();
		if (!$container->getUserPermissions($userId)->automatedSolution()->canEdit())
		{
			return Json::encode([
				'error' => 'AccessDenied',
				'message' => 'You do not have permission to create automated solutions',
			]);
		}

		$limitCheckResult = RestrictionManager::getAutomatedSolutionLimitRestriction()->check();
		if (!$limitCheckResult->isSuccess())
		{
			return Json::encode([
				'error' => 'AutomatedSolutionLimitExceeded',
				'message' => implode(', ', $limitCheckResult->getErrorMessages()),
			]);
		}

		$manager = $container->getAutomatedSolutionManager();
		$result = $manager->addAutomatedSolution(['TITLE' => $title]);

		if (!$result->isSuccess())
		{
			return Json::encode([
				'error' => 'Failed to create automated solution',
				'details' => implode(', ', $result->getErrorMessages()),
			]);
		}

		$fields = $result->getData()['fields'] ?? [];

		return Json::encode([
			'id' => (int)($fields['ID'] ?? 0),
		]);
	}
}
