<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Main\Loader;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Mailbox\ListMailboxesTool;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Mailbox\ListMailSendersTool;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\CreateCalendarEventFromEmailTool;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\CreateChatFromEmailTool;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\CreateCrmEmailActivityTool;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\CreateFeedPostFromEmailTool;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\CreateTaskFromEmailTool;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\ForwardEmailTool;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\GetEmailContentTool;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\GetEmailThreadTool;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\MoveEmailsToFolderTool;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\RemoveCrmEmailActivityTool;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\ReplyToEmailTool;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\SearchEmailsTool;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\SearchEmployeeEmailsTool;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\ListMailRecipientsTool;
use Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message\SendEmailTool;

class MailboxToolSet extends BaseToolSet
{
	public const TOOLS = [
		SearchEmailsTool::class,
		GetEmailContentTool::class,
		GetEmailThreadTool::class,
		ListMailboxesTool::class,
		ListMailSendersTool::class,
		ListMailRecipientsTool::class,
		CreateCrmEmailActivityTool::class,
		RemoveCrmEmailActivityTool::class,
		CreateTaskFromEmailTool::class,
		CreateCalendarEventFromEmailTool::class,
		CreateChatFromEmailTool::class,
		CreateFeedPostFromEmailTool::class,
		MoveEmailsToFolderTool::class,
		SearchEmployeeEmailsTool::class,
		SendEmailTool::class,
		ForwardEmailTool::class,
		ReplyToEmailTool::class,
	];

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

	public function canRun(int $userId): bool
	{
		if (Loader::includeModule('humanresources'))
		{
			return \Bitrix\HumanResources\Service\Container::getUserService()->isEmployee($userId);
		}

		return false;
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto(self::TOOLS);
	}
}
