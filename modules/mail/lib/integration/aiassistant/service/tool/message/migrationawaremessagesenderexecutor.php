<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Mail\Helper\Message\MailboxMigrationActionException;
use Bitrix\Main\SystemException;

final class MigrationAwareMessageSenderExecutor
{
	public static function execute(callable $send): array
	{
		try
		{
			return $send();
		}
		catch (MailboxMigrationActionException $exception)
		{
			if (method_exists(McpException::class, 'withPublicCode'))
			{
				throw McpException::withPublicCode(
					$exception->getMessage(),
					$exception->errorCode,
					$exception,
				);
			}

			throw new McpException($exception->getMessage(), previous: $exception);
		}
		catch (SystemException $exception)
		{
			throw new McpException($exception->getMessage(), previous: $exception);
		}
	}
}

