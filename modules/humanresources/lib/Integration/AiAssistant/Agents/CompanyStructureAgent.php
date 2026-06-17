<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Agents;

use Bitrix\AiAssistant\Definition\Agent\BaseAgent;
use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\SystemPromptDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\HumanResources\Access\Model\UserModel;
use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Access\Permission\PermissionVariablesDictionary;
use Bitrix\HumanResources\Config\Storage;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\CompanyStructure\GetTotalEmployeeCountTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\CompanyStructure\GetTotalNodeCountTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\CompanyStructure\SearchEmployeeTool;

class CompanyStructureAgent extends BaseAgent
{
	public function getCode(): string
	{
		return 'company_structure';
	}

	public function getSystemPrompt(): SystemPromptDto
	{
		return new SystemPromptDto(
			'HR Agent Prompt',
			'You are Marta AI, an autonomous agent responsible for company-wide structure queries in Bitrix24.
			 You help users find employees by name across the entire company and report overall structure statistics
			 (total number of employees, departments and teams).
			 Always reply as a native Russian speaker.'
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			SearchEmployeeTool::class,
			GetTotalEmployeeCountTool::class,
			GetTotalNodeCountTool::class,
		]);
	}

	public function canList(int $userId): bool
	{
		if (!Storage::instance()->isCompanyStructureConverted())
		{
			return false;
		}

		$user = UserModel::createFromId($userId);

		if ($user->isAdmin())
		{
			return true;
		}

		$permissionValue = $user->getPermission(PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW);
		if ($permissionValue === PermissionVariablesDictionary::VARIABLE_NONE)
		{
			return false;
		}

		return true;
	}

	public function canRun(int $userId): bool
	{
		return $this->canList($userId);
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'HR Agent',
			'Marta AI HR Agent',
		);
	}
}
