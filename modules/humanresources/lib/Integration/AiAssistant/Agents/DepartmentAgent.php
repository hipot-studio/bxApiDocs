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
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentChangeParentTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentCreateTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentEditEmployeesTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentEditTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentGetCommunicationsTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentMoveEmployeesTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentSaveCommunicationsTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentGetChildrenTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentShowTool;

class DepartmentAgent extends BaseAgent
{
	public function getCode(): string
	{
		return 'department';
	}

	public function getSystemPrompt(): SystemPromptDto
	{
		return new SystemPromptDto(
			'HR Agent Prompt',
			'You are Marta AI, an autonomous agent responsible for managing department tasks in Bitrix24.
			 Your role is to assist users in setting up and managing their department structure effectively. 
			 Always reply as a native Russian speaker.'
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			DepartmentCreateTool::class,
			DepartmentChangeParentTool::class,
			DepartmentEditEmployeesTool::class,
			DepartmentEditTool::class,
			DepartmentMoveEmployeesTool::class,
			DepartmentSaveCommunicationsTool::class,
			DepartmentShowTool::class,
			DepartmentGetChildrenTool::class,
			DepartmentGetCommunicationsTool::class,
		]);
	}

	public function canList(int $userId): bool
	{
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
