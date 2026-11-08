<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Exception;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;

Loader::requireModule('aiassistant');

final class ToolExecuteException extends McpException
{
	public function __construct(AbstractTool $tool, ToolResult $result)
	{
		$toolName = $tool->getName();
		$errors = self::formatErrors($result->getErrors());

		parent::__construct(
			message: "Tool '{$toolName}' failed. {$errors}"
		);
	}

	public static function createFromErrors(AbstractTool $tool, ErrorCollection $errors): self
	{
		return new self($tool, ToolResult::fail($errors));
	}

	/**
	 * @param Error[] $errors
	 */
	private static function formatErrors(array $errors): string
	{
		if (empty($errors))
		{
			return 'No error details were returned.';
		}

		$messages = [];
		foreach ($errors as $error)
		{
			$message = rtrim($error->getMessage(), " \t\n\r\0\x0B.");
			$code = $error->getCode();

			if ($code !== null && (string)$code !== '' && (string)$code !== '0')
			{
				$message .= " (code: {$code})";
			}

			$messages[] = $message;
		}

		return 'Errors: ' . implode('; ', $messages) . '. Check the tool input arguments and retry.';
	}
}
