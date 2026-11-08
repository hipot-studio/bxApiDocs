<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\TestRun;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__DIR__ . '/TestRunService.php');

/**
 * Stable reason why a test run of a trigger node was rejected.
 *
 * Backing values are the codes of the errors carried by the result of the service, so the designer
 * branches on the code and never on the message.
 */
enum ErrorCode: string
{
	case ActiveRunExists = 'ACTIVE_RUN_EXISTS';
	case DraftNotFound = 'DRAFT_NOT_FOUND';
	case DraftChanged = 'DRAFT_CHANGED';
	case TriggerNotSupported = 'TRIGGER_NOT_SUPPORTED';
	case DocumentSourceUnavailable = 'DOCUMENT_SOURCE_UNAVAILABLE';
	case DocumentCreateFailed = 'DOCUMENT_CREATE_FAILED';
	case DocumentNotFound = 'DOCUMENT_NOT_FOUND';
	case StartFailed = 'START_FAILED';
	case SessionNotFound = 'SESSION_NOT_FOUND';
	case InvalidValues = 'INVALID_VALUES';
	case FinishFailed = 'FINISH_FAILED';

	public function getMessage(): string
	{
		return Loc::getMessage($this->getPhraseId()) ?? '';
	}

	public function createError(mixed $customData = null): Error
	{
		return new Error($this->getMessage(), $this->value, $customData);
	}

	private function getPhraseId(): string
	{
		return match ($this)
		{
			self::ActiveRunExists => 'BIZPROC_PUBLIC_SERVICE_TEST_RUN_ERROR_ACTIVE_RUN_EXISTS',
			self::DraftNotFound => 'BIZPROC_PUBLIC_SERVICE_TEST_RUN_ERROR_DRAFT_NOT_FOUND',
			self::DraftChanged => 'BIZPROC_PUBLIC_SERVICE_TEST_RUN_ERROR_DRAFT_CHANGED',
			self::TriggerNotSupported => 'BIZPROC_PUBLIC_SERVICE_TEST_RUN_ERROR_TRIGGER_NOT_SUPPORTED',
			self::DocumentSourceUnavailable => 'BIZPROC_PUBLIC_SERVICE_TEST_RUN_ERROR_DOCUMENT_SOURCE_UNAVAILABLE',
			self::DocumentCreateFailed => 'BIZPROC_PUBLIC_SERVICE_TEST_RUN_ERROR_DOCUMENT_CREATE_FAILED',
			self::DocumentNotFound => 'BIZPROC_PUBLIC_SERVICE_TEST_RUN_ERROR_DOCUMENT_NOT_FOUND',
			self::StartFailed => 'BIZPROC_PUBLIC_SERVICE_TEST_RUN_ERROR_START_FAILED',
			self::SessionNotFound => 'BIZPROC_PUBLIC_SERVICE_TEST_RUN_ERROR_SESSION_NOT_FOUND',
			self::InvalidValues => 'BIZPROC_PUBLIC_SERVICE_TEST_RUN_ERROR_INVALID_VALUES',
			self::FinishFailed => 'BIZPROC_PUBLIC_SERVICE_TEST_RUN_ERROR_FINISH_FAILED',
		};
	}
}
