<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Exception;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;

Loader::requireModule('aiassistant');

final class ToolExecuteException extends McpException
{
	public function __construct(AbstractTool $tool, ToolResult $result)
	{
		$toolName = $tool->getName();
		$errors = implode(', ', $result->getErrors());

		parent::__construct(
			message: "Failed to call tool {$toolName}: {$errors}"
		);
	}

	public static function createFromErrors(AbstractTool $tool, ErrorCollection $errors): self
	{
		return new self($tool, ToolResult::fail($errors));
	}
}
