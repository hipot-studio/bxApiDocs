<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\ConfigureAutomatedSolution;

use Bitrix\Crm\AutomatedSolution\Action\BindTypeToAutomatedSolution;
use Bitrix\Crm\AutomatedSolution\Action\UnbindTypeFromAutomatedSolution;
use Bitrix\Crm\AutomatedSolution\Entity\AutomatedSolutionTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ConfigureAutomatedSolutionTool extends AbstractTool
{
	public function canList(int $userId): bool
	{
		return true;
	}

	public function canRun(int $userId): bool
	{
		return true;
	}

	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'configure_smart_process_automated_solution',
			description: 'Binds or unbinds a CRM smart process to/from an automated solution (workplace).'
				. ' Changing the workplace recreates pipeline permissions;'
				. ' a smart process bound to another workplace is unbound from it automatically.'
				. ' Pass automatedSolutionId=0 to unbind: the smart process moves to the CRM section.'
				. ' Use search_dynamic_type and search_automated_solution to find the IDs first.',
		))
			->setProperties([
				(new IntegerProperty(
					'smartProcessId',
					'ENTITY_TYPE_ID of the smart process. Use search_dynamic_type to find it.',
				))
					->setIsRequired(true)
				,
				(new IntegerProperty(
					'automatedSolutionId',
					'ID of the target automated solution (workplace).'
						. ' Use search_automated_solution to find it.'
						. ' Pass 0 to unbind the smart process from its current workplace'
						. ' (it will move to the CRM section).',
				))
					->setIsRequired(true)
				,
			])
		;
	}

	protected function getArgsDtoClass(): string
	{
		return ConfigureAutomatedSolutionToolDto::class;
	}

	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		/** @var ConfigureAutomatedSolutionToolDto $args */
		$smartProcessId = $args->smartProcessId;
		$automatedSolutionId = $args->automatedSolutionId;

		$type = Container::getInstance()->getTypeByEntityTypeId($smartProcessId);
		if ($type === null)
		{
			return ToolResult::fail(
				"Smart process with ENTITY_TYPE_ID={$smartProcessId} was not found.",
			);
		}

		$userPermissions = Container::getInstance()->getUserPermissions($args->getUserId());

		$canUpdate = $userPermissions->dynamicType()->canUpdate($smartProcessId);
		if (!$canUpdate)
		{
			return ToolResult::fail(
				"Access denied: you do not have permission to manage smart process"
					. " with ENTITY_TYPE_ID={$smartProcessId}.",
			);
		}

		$automatedSolutionManager = Container::getInstance()->getAutomatedSolutionManager();
		$currentAutomatedSolutionId = $type->getCustomSectionId();

		// A smart process whose current workplace was imported from the Marketplace
		// cannot have its workplace changed (mirrors the standard type controller guard).
		if ($currentAutomatedSolutionId > 0 && $currentAutomatedSolutionId !== $automatedSolutionId)
		{
			$currentSolution = $automatedSolutionManager->getAutomatedSolution($currentAutomatedSolutionId);
			if (
				$currentSolution !== null
				&& AutomatedSolutionTable::isImportedFromMarketplace((int)($currentSolution['SOURCE_ID'] ?? 0))
			)
			{
				return ToolResult::fail(
					"Access denied: the smart process is bound to a workplace imported from the"
						. " Marketplace; its workplace cannot be changed.",
				);
			}
		}

		if ($automatedSolutionId === 0)
		{
			// Unbind from current workplace
			if ($currentAutomatedSolutionId === null || $currentAutomatedSolutionId === 0)
			{
				return ToolResult::success(
					smartProcessId: $smartProcessId,
					automatedSolutionId: null,
					message: 'Smart process is already not bound to any workplace. No changes made.',
				);
			}

			$canEditCurrentSolution = $userPermissions->automatedSolution()->canEdit($currentAutomatedSolutionId);
			if (!$canEditCurrentSolution)
			{
				return ToolResult::fail(
					"Access denied: you do not have permission to edit automated solution"
						. " with ID={$currentAutomatedSolutionId}.",
				);
			}

			$result = (new UnbindTypeFromAutomatedSolution($type, $currentAutomatedSolutionId))->execute();
			if (!$result->isSuccess())
			{
				$messages = implode('; ', $result->getErrorMessages());

				return ToolResult::fail("Failed to unbind smart process from workplace: {$messages}");
			}

			return ToolResult::success(
				smartProcessId: $smartProcessId,
				automatedSolutionId: null,
				message: 'Smart process successfully unbound from workplace and moved to the CRM section.',
			);
		}

		// Bind to target workplace
		if ($currentAutomatedSolutionId === $automatedSolutionId)
		{
			return ToolResult::success(
				smartProcessId: $smartProcessId,
				automatedSolutionId: $automatedSolutionId,
				message: 'Smart process is already bound to this workplace. No changes made.',
			);
		}

		$targetSolution = $automatedSolutionManager->getAutomatedSolution($automatedSolutionId);
		if ($targetSolution === null)
		{
			return ToolResult::fail(
				"Automated solution with ID={$automatedSolutionId} was not found.",
			);
		}

		$canEditTargetSolution = $userPermissions->automatedSolution()->canEdit($automatedSolutionId);
		if (!$canEditTargetSolution)
		{
			return ToolResult::fail(
				"Access denied: you do not have permission to edit automated solution"
					. " with ID={$automatedSolutionId}.",
			);
		}

		// Rebinding detaches the smart process from its current workplace, so editing
		// the source workplace must be permitted too (the unbind path checks the same).
		if (
			$currentAutomatedSolutionId > 0
			&& !$userPermissions->automatedSolution()->canEdit($currentAutomatedSolutionId)
		)
		{
			return ToolResult::fail(
				"Access denied: you do not have permission to edit automated solution"
					. " with ID={$currentAutomatedSolutionId}.",
			);
		}

		$result = (new BindTypeToAutomatedSolution($type, $automatedSolutionId))->execute();
		if (!$result->isSuccess())
		{
			$messages = implode('; ', $result->getErrorMessages());

			return ToolResult::fail("Failed to bind smart process to workplace: {$messages}");
		}

		return ToolResult::success(
			smartProcessId: $smartProcessId,
			automatedSolutionId: $automatedSolutionId,
		);
	}
}
