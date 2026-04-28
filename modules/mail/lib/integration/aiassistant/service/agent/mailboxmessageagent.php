<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Agent;

use Bitrix\AiAssistant\Definition\Agent\BaseAgent;
use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\SystemPromptDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\Main\Loader;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\SearchMessagesTool;

class MailboxMessageAgent extends BaseAgent
{
	public function getCode(): string
	{
		return 'mailbox_message';
	}

	public function getSystemPrompt(): SystemPromptDto
	{
		return new SystemPromptDto(
			'Mailbox Message Agent Prompt',
			'You are Marta AI, an autonomous mail assistant in Bitrix24.
			 Your role is to help users search, analyze, and manage their mailbox messages effectively.
			 You can find emails by various criteria such as sender, date, subject, and content.
			 Always reply as a native Russian speaker.'
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		$tools = [
			SearchMessagesTool::class,
		];

		return new UsesToolsDto($tools);
	}

	public function canList(int $userId): bool
	{
		return true;
	}

	public function canRun(int $userId): bool
	{
		return true;
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'Mail Agent',
			'Marta AI Mail Agent',
		);
	}
}
