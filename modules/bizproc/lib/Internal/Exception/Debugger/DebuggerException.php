<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Exception\Debugger;

use Bitrix\Main\Localization\Loc;
use Exception;

class DebuggerException extends Exception
{
	public static function currentSessionNotSet(): self
	{
		return new self(Loc::getMessage('BP_DEBUG_CURRENT_SESSION_NOT_SET'));
	}

	public static function sessionNotFound(int $sessionId): self
	{
		return new self(Loc::getMessage('BP_DEBUG_SESSION_NOT_FOUND', ['#SESSION_ID#' => $sessionId]), 404);
	}

	public static function traceNotFound(int $traceId): self
	{
		return new self(Loc::getMessage('BP_DEBUG_TRACE_NOT_FOUND', ['#TRACE_ID#' => $traceId]), 404);
	}

	public static function sessionAlreadyEnded(): self
	{
		return new self(Loc::getMessage('BP_DEBUG_SESSION_ALREADY_ENDED'), 400);
	}

	public static function sessionAlreadyExists(string $sessionId): self
	{
		return new self(
			Loc::getMessage('BP_DEBUG_SESSION_ALREADY_EXISTS', ['#SESSION_ID#' => $sessionId]),
			409,
		);
	}

	public static function invalidUserId(): self
	{
		return new self(Loc::getMessage('BP_DEBUG_INVALID_USER_ID'), 400);
	}

	public static function invalidTraceId(string $traceId = ''): self
	{
		return new self(Loc::getMessage('BP_DEBUG_INVALID_TRACE_ID', ['#TRACE_ID#' => $traceId]), 400);
	}

	public static function invalidTraceType(string $type): self
	{
		return new self(Loc::getMessage('BP_DEBUG_INVALID_TRACE_TYPE', ['#TYPE#' => $type]), 400);
	}

	public static function activeSessionExists(string $sessionId, array $documentId, int $templateId): self
	{
		$docIdStr = implode(':', $documentId);

		return new self(
			Loc::getMessage('BP_DEBUG_ACTIVE_SESSION_EXISTS', [
				'#SESSION_ID#' => $sessionId,
				'#DOCUMENT_ID#' => $docIdStr,
				'#TEMPLATE_ID#' => $templateId,
			]),
			409,
		);
	}

	public static function invalidDocumentId(string $message = ''): self
	{
		if (!empty($message))
		{
			$errorMessage = Loc::getMessage('BP_DEBUG_INVALID_DOCUMENT_ID_WITH_MESSAGE', ['#MESSAGE#' => $message]);
		}
		else
		{
			$errorMessage = Loc::getMessage('BP_DEBUG_INVALID_DOCUMENT_ID');
		}

		return new self($errorMessage, 400);
	}

	public static function invalidTimestamp(string $message = ''): self
	{
		if (!empty($message))
		{
			$errorMessage = Loc::getMessage('BP_DEBUG_INVALID_TIMESTAMP_WITH_MESSAGE', ['#MESSAGE#' => $message]);
		}
		else
		{
			$errorMessage = Loc::getMessage('BP_DEBUG_INVALID_TIMESTAMP');
		}

		return new self($errorMessage, 400);
	}

	public static function accessDenied(): self
	{
		return new self(Loc::getMessage('BP_DEBUG_ACCESS_DENIED'), 403);
	}
}
