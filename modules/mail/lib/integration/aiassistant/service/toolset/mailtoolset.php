<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Main\Loader;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\SearchMessagesTool;

class MailboxToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'mailbox';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'Mail Tool Set',
			'Public Mail Tool Set for email operations',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		$tools = [
			SearchMessagesTool::class,
		];

		return new UsesToolsDto($tools);
	}

	protected function isAvailable(): bool
	{
		return Loader::includeModule('aiassistant');
	}
}
