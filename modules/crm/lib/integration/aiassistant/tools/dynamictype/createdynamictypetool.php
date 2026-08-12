<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\DynamicType;

use Bitrix\Crm\AutomatedSolution\Entity\AutomatedSolutionTable;
use Bitrix\Crm\Integration\AiAssistant\Tools\BaseCrmTool;
use Bitrix\Crm\Integration\IntranetManager;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Web\Json;

final class CreateDynamicTypeTool extends BaseCrmTool
{
	public function getName(): string
	{
		return 'create_dynamic_type';
	}

	public function getDescription(): string
	{
		return 'Creates a new dynamic type with all features enabled inside the specified automated solution. '
			. 'Requires a valid automatedSolutionId — creating dynamic types outside an automated solution is not allowed. ';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'title' => [
					'description' => 'Name of the dynamic type to create',
					'type' => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				],
				'automatedSolutionId' => [
					'description' => 'ID of the automated solution to create the dynamic type in. '
						. 'Use search_automated_solution to find it by name. Must be a positive integer.',
					'type' => 'integer',
					'minimum' => 1,
				],
			],
			'additionalProperties' => false,
			'required' => ['title', 'automatedSolutionId'],
		];
	}

	protected function executeTool(int $userId, ...$args): string
	{
		$title = trim((string)($args['title'] ?? ''));
		$automatedSolutionId = (int)($args['automatedSolutionId'] ?? 0);

		if ($title === '')
		{
			return Json::encode(['error' => 'Title is required and cannot be empty']);
		}

		if ($automatedSolutionId <= 0)
		{
			return Json::encode(['error' => 'automatedSolutionId must be a positive integer']);
		}

		$container = Container::getInstance();
		$manager = $container->getAutomatedSolutionManager();

		$automatedSolution = $manager->getAutomatedSolution($automatedSolutionId);
		if ($automatedSolution === null)
		{
			return Json::encode([
				'error' => 'AutomatedSolutionNotFound',
				'message' => "Automated solution with ID {$automatedSolutionId} not found",
			]);
		}

		if (!IntranetManager::isCustomSectionsAvailable())
		{
			return Json::encode([
				'error' => 'CustomSectionsUnavailable',
				'message' => 'Custom sections are not available on this portal, dynamic types cannot be bound to automated solutions',
			]);
		}

		if (AutomatedSolutionTable::isImportedFromMarketplace((int)($automatedSolution['SOURCE_ID'] ?? 0)))
		{
			return Json::encode([
				'error' => 'AutomatedSolutionImportedFromMarketplace',
				'message' => "Cannot bind dynamic types to an automated solution imported from the marketplace",
			]);
		}

		$userPermissions = $container->getUserPermissions($userId);
		if (!$userPermissions->automatedSolution()->isAutomatedSolutionAdmin($automatedSolutionId))
		{
			return Json::encode([
				'error' => 'AccessDenied',
				'message' => 'You do not have admin permissions for this automated solution',
			]);
		}

		$restriction = RestrictionManager::getDynamicTypesLimitRestriction();
		if ($restriction->isCreateTypeRestricted())
		{
			return Json::encode([
				'error' => 'DynamicTypesLimitExceeded',
				'message' => $restriction->getCreateTypeRestrictedError()->getMessage(),
			]);
		}

		$dataClass = $container->getDynamicTypeDataClass();
		$name = $dataClass::generateName($title);
		if ($name === null)
		{
			return Json::encode([
				'error' => 'Failed to generate internal name from the provided title',
			]);
		}

		$type = $dataClass::createObject();
		$type->setTitle($title);
		$type->setName($name);

		// Apply "all inclusive" preset flags
		$type->setIsCategoriesEnabled(true);
		$type->setIsStagesEnabled(true);
		$type->setIsBeginCloseDatesEnabled(true);
		$type->setIsClientEnabled(true);
		$type->setIsLinkWithProductsEnabled(true);
		$type->setIsMycompanyEnabled(true);
		$type->setIsDocumentsEnabled(true);
		$type->setIsSourceEnabled(true);
		$type->setIsUseInUserfieldEnabled(true);
		$type->setIsObserversEnabled(true);
		$type->setIsRecurringEnabled(true);
		$type->setIsRecyclebinEnabled(true);
		$type->setIsAutomationEnabled(true);
		$type->setIsBizProcEnabled(true);
		$type->setIsSetOpenPermissions(true);
		$type->setIsCountersEnabled(true);

		$saveResult = $type->save();
		if (!$saveResult->isSuccess())
		{
			return Json::encode([
				'error' => 'Failed to create dynamic type',
				'details' => implode(', ', $saveResult->getErrorMessages()),
			]);
		}

		$bindResult = $manager->bindTypeToAutomatedSolution($type, $automatedSolutionId);
		if (!$bindResult->isSuccess())
		{
			$type->delete();

			return Json::encode([
				'error' => 'Failed to bind dynamic type to automated solution',
				'details' => implode(', ', $bindResult->getErrorMessages()),
			]);
		}

		return Json::encode([
			'id' => $type->getEntityTypeId(),
		]);
	}
}
