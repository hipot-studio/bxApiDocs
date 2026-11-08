<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service;

use Bitrix\Crm\AutomatedSolution\AutomatedSolutionManager;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response;

final readonly class SmartProcessService
{
	public function __construct(
		private AutomatedSolutionManager $automatedSolutionManager,
		private RestrictionManager $restrictionManager,
	)
	{
	}

	public function createSmartProcess(
		int $userId,
		string $title,
		?int $automatedSolutionId = null,
	): Result
	{
		$permissions = Container::getInstance()->getUserPermissions($userId);
		$hasAccess = $automatedSolutionId === null
			? $permissions->isCrmAdmin()
			: $permissions
				->automatedSolution()
				->isAutomatedSolutionAdmin($automatedSolutionId)
		;
		if (!$hasAccess)
		{
			return Result::failAccessDenied();
		}

		$isRestricted = $this->restrictionManager::getDynamicTypesLimitRestriction()->isCreateTypeRestricted();
		if ($isRestricted)
		{
			return Result::fail('Smart process creation is restricted by the current CRM plan or limit.');
		}

		$isAutomatedSolutionExists = (
			$automatedSolutionId === null
			|| $this->automatedSolutionManager->isAutomatedSolutionExists($automatedSolutionId)
		);

		if (!$isAutomatedSolutionExists)
		{
			return Result::fail(
				"Automated solution with ID {$automatedSolutionId} was not found. Use list_automated_solutions to get available IDs."
			);
		}

		$smartProcessTable = Container::getInstance()->getDynamicTypeDataClass();
		$smartProcess = $smartProcessTable::createObject();

		$smartProcessSlug = $smartProcessTable::generateName($title);
		$smartProcess
			->setTitle($title)
			->setName($smartProcessSlug)
			->setIsCategoriesEnabled(true)
			->setIsStagesEnabled(true)
			->setIsBeginCloseDatesEnabled(true)
			->setIsClientEnabled(true)
			->setIsLinkWithProductsEnabled(true)
			->setIsMycompanyEnabled(true)
			->setIsDocumentsEnabled(true)
			->setIsSourceEnabled(true)
			->setIsObserversEnabled(true)
			->setIsRecurringEnabled(true)
			->setIsRecyclebinEnabled(true)
			->setIsAutomationEnabled(true)
			->setIsBizProcEnabled(true)
			->setIsSetOpenPermissions(false)
			->setIsCountersEnabled(true)
			->setIsUseInUserfieldEnabled(true)
		;

		$saveResult = $smartProcess->save();
		if (!$saveResult->isSuccess())
		{
			return Result::fail($saveResult->getErrorCollection());
		}

		if ($automatedSolutionId !== null)
		{
			$bindResult = $this
				->automatedSolutionManager
				->bindTypeToAutomatedSolution($smartProcess, $automatedSolutionId)
			;

			if (!$bindResult->isSuccess())
			{
				$smartProcess->delete();

				return Result::fail(
					"Smart process was created but could not be bound to automated solution {$automatedSolutionId}."
				);
			}
		}

		$responseSmartProcess = Response\EntityType::fromDynamicType($smartProcess);

		return Result::success(smartProcess: $responseSmartProcess);
	}
}
