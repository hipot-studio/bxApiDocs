<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service;

use Bitrix\Crm\AutomatedSolution\Action\Read\Fetch;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response;

final readonly class AutomatedSolutionService
{
	public function addAutomatedSolution(int $userId, string $title): Result
	{
		$permissions = Container::getInstance()->getUserPermissions($userId)->automatedSolution();
		if (!$permissions->canEdit())
		{
			return Result::failAccessDenied();
		}

		$limitCheckResult = RestrictionManager::getAutomatedSolutionLimitRestriction()->check();
		if (!$limitCheckResult->isSuccess())
		{
			return Result::fail($limitCheckResult->getErrorCollection());
		}

		$fields = [
			'TITLE' => $title,
		];

		$manager = Container::getInstance()->getAutomatedSolutionManager();

		$result = $manager->addAutomatedSolution($fields);
		if (!$result->isSuccess())
		{
			return Result::fail($result->getErrorCollection());
		}

		$automatedSolutionId = (int)($result->getData()['fields']['ID'] ?? 0);
		if ($automatedSolutionId <= 0)
		{
			return Result::fail("Failed to create automated solution '{$title}': CRM did not return a created ID.");
		}

		$automatedSolution = $manager->getAutomatedSolution($automatedSolutionId);
		if ($automatedSolution === null)
		{
			return Result::fail(
				"Automated solution {$automatedSolutionId} was created but could not be loaded. Try list_automated_solutions before using it."
			);
		}

		return Result::success(automatedSolution: Response\AutomatedSolution::tryFromArray($automatedSolution));
	}

	public function listAutomatedSolutions(int $userId, ?string $query = null): Result
	{
		$filter = [];
		if ($query !== null && !empty(trim($query)))
		{
			$filter['%TITLE'] = trim($query);
		}

		$result = (new Fetch(filter: $filter))->execute();
		if (!$result->isSuccess())
		{
			return Result::fail($result->getErrorCollection());
		}

		$permissions = Container::getInstance()->getUserPermissions($userId)->automatedSolution();

		$automatedSolutions = [];
		foreach (($result->getData()['automatedSolutions'] ?? []) as $automatedSolution)
		{
			$automatedSolutionId = (int)($automatedSolution['ID'] ?? 0);
			if ($automatedSolutionId <= 0)
			{
				continue;
			}

			if (!$permissions->canEdit($automatedSolutionId))
			{
				continue;
			}

			$automatedSolutions[] = Response\AutomatedSolution::tryFromArray($automatedSolution);
		}

		return Result::success(automatedSolutions: $automatedSolutions);
	}
}
