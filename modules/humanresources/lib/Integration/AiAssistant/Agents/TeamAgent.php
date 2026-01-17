<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\Agents;

use Bitrix\AiAssistant\Definition\Agent\BaseAgent;
use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\SystemPromptDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\HumanResources\Access\Model\UserModel;
use Bitrix\HumanResources\Access\Permission\Mapper\TeamPermissionMapper;
use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Access\Permission\PermissionHelper;
use Bitrix\HumanResources\Access\Permission\PermissionVariablesDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamChangeParentTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamCreateTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamEditEmployeesTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamEditTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamGetCommunicationsTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamMoveEmployeesTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamSaveCommunicationsTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamGetChildrenTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamShowTool;

class TeamAgent extends BaseAgent
{
	public function getCode(): string
	{
		return 'team';
	}

	public function getSystemPrompt(): SystemPromptDto
	{
		return new SystemPromptDto(
			'HR Agent Prompt',
			'You are Marta AI, an autonomous agent responsible for managing team tasks in Bitrix24.
			 Your role is to assist users in setting up and managing their team structure effectively. 
			 Always reply as a native Russian speaker.'
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			TeamCreateTool::class,
			TeamChangeParentTool::class,
			TeamEditEmployeesTool::class,
			TeamEditTool::class,
			TeamMoveEmployeesTool::class,
			TeamSaveCommunicationsTool::class,
			TeamShowTool::class,
			TeamGetChildrenTool::class,
			TeamGetCommunicationsTool::class,
		]);
	}

	public function canList(int $userId): bool
	{
		$user = UserModel::createFromId($userId);

		if ($user->isAdmin())
		{
			return true;
		}

		$permissionCollection = PermissionHelper::getPermissionValue(PermissionDictionary::HUMAN_RESOURCES_TEAM_VIEW, $userId);
		$teamPermissionMapper = TeamPermissionMapper::createFromCollection($permissionCollection);
		if (
			$teamPermissionMapper->getTeamPermissionValue() === PermissionVariablesDictionary::VARIABLE_NONE
			&& $teamPermissionMapper->getDepartmentPermissionValue() === PermissionVariablesDictionary::VARIABLE_NONE
		)
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
