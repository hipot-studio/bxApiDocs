<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AutomatedSolution\Update;

use Bitrix\Crm\AutomatedSolution\AutomatedSolutionManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class UpdateTool extends AbstractTool
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
			name: 'update_automated_solution',
			description: 'Updates attributes of a CRM automated solution (workplace).'
				. ' Currently supports renaming. Renaming changes the URL of the workplace section.'
				. ' Use search_automated_solution to find the automatedSolutionId first.',
		))
			->setProperties([
				(new IntegerProperty(
					'automatedSolutionId',
					'ID of the automated solution (workplace).'
						. ' Use search_automated_solution to find it.',
				))
					->setIsRequired(true)
				,
				(new StringProperty(
					'title',
					'New name for the automated solution.',
				))
					->setIsRequired(true)
				,
			])
		;
	}

	protected function getArgsDtoClass(): string
	{
		return UpdateToolDto::class;
	}

	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		/** @var UpdateToolDto $args */
		$id = $args->automatedSolutionId;
		$title = trim((string)$args->title);

		$canEdit = Container::getInstance()
			->getUserPermissions($args->getUserId())
			->automatedSolution()
			->canEdit($id)
		;
		if (!$canEdit)
		{
			return ToolResult::fail(
				"Access denied: you do not have permission to edit automated solution with ID={$id}.",
			);
		}

		/** @var AutomatedSolutionManager $manager */
		$manager = Container::getInstance()->getAutomatedSolutionManager();

		$result = $manager->updateAutomatedSolution($id, ['TITLE' => $title]);
		if (!$result->isSuccess())
		{
			$messages = implode('; ', $result->getErrorMessages());

			return ToolResult::fail("Failed to update automated solution: {$messages}");
		}

		return ToolResult::success(
			automatedSolutionId: $id,
			title: $title,
		);
	}
}
