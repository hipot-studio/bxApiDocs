<?php

namespace Bitrix\Call\Integration\AI;

use Bitrix\Call\Integration\AI\Task\AITask;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class CallAIError extends \Bitrix\Call\Error
{
	public const
		AI_MODULE_ERROR = 'AI_MODULE_ERROR',
		AI_UNAVAILABLE_ERROR = 'AI_UNAVAILABLE_ERROR',
		AI_SETTINGS_ERROR = 'AI_SETTINGS_ERROR',
		AI_AGREEMENT_ERROR = 'AI_AGREEMENT_ERROR',
		AI_UNSUPPORTED_TRACK_ERROR = 'AI_UNSUPPORTED_TRACK_ERROR',
		AI_EMPTY_PAYLOAD_ERROR = 'AI_EMPTY_PAYLOAD_ERROR',
		AI_NOT_ENOUGH_BAAS_ERROR = 'AI_NOT_ENOUGH_BAAS_ERROR',
		AI_RECORD_TOO_SHORT = 'AI_RECORD_TOO_SHORT',
		AI_TASK_START_FAIL = 'AI_TASK_START_FAIL',
		AI_TASK_FAILED = 'AI_TASK_FAILED',
		AI_TRACKPACK_NOT_RECEIVED = 'AI_TRACKPACK_NOT_FOUND',
		AI_TRANSCRIBE_TASK_ERROR = 'AI_TRANSCRIBE_TASK_ERROR',
		AI_OVERVIEW_TASK_ERROR = 'AI_OVERVIEW_TASK_ERROR'
	;

	/**
	 * Checks if error fired by ai module.
	 * @see \Bitrix\AI\Engine\Engine::onResponseError
	 */
	public function isAiGeneratedError(): bool
	{
		// Errors comes from AI module are started with prefix AI_ENGINE_ERROR_
		return
			str_starts_with($this->getCode(), 'AI_ENGINE_ERROR')
			|| str_starts_with($this->getCode(), 'LIMIT_IS_EXCEEDED')
			|| str_starts_with($this->getCode(), 'CLOUD_REGISTRATION')
			|| $this->getCode() == 'RATE_LIMIT'
		;
	}

	/**
	 * @param string $errorCode
	 * @param \Bitrix\Main\Error $processingError
	 * @param AITask $processingTask
	 * @return static
	 */
	public static function constructTaskError(string $errorCode, $processingError, AITask $processingTask): self
	{
		$error = new self($errorCode);

		if ($processingError instanceof \Bitrix\Main\Error)
		{
			$error->code = $processingError->getCode();
			$error->message = $processingError->getMessage();
		}

		if (
			$error->code !== 'HASH_EXPIRED'
			&& str_starts_with($error->code, 'AI_ENGINE_ERROR')
			&& ($errorRow = self::detectRowError($error->message, $processingTask))
		)
		{
			if (str_starts_with($errorRow, '[ERROR]'))
			{
				$error->description = $errorRow;
			}
			else
			{
				$error->message = $errorRow;
			}
		}

		return $error;
	}

	private static function detectRowError(string $againstError, AITask $task): string
	{
		if (\Bitrix\Main\Loader::includeModule('ai'))
		{
			$history = \Bitrix\AI\Model\HistoryTable::getList([
				'filter' => [
					'=CONTEXT_MODULE' => 'call',
					'=CONTEXT_ID' => $task->getContextId()
				],
				'order' => ['ID' => 'DESC'],
				'limit' => 1,
			]);
			if (
				($row = $history->fetch())
				&& !empty($row['RESULT_TEXT'])
				&& $row['RESULT_TEXT'] != $againstError
			)
			{
				return $row['RESULT_TEXT'];
			}
		}

		return '';
	}
}
