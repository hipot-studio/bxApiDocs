<?php

namespace Bitrix\Call\Integration\AI;

use Bitrix\Call\Integration\AI\Task\AITask;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * @internal
 */

class CallAIError extends \Bitrix\Call\Error
{
	/**
	 * Override resolves the new "ERROR_{code}" lang key first and injects
	 * "#COPILOT_NAME#" so call AI error messages follow the portal license region.
	 */
	protected function loadErrorMessage($code, $replacements): string
	{
		$replacements['#COPILOT_NAME#'] = CallAISettings::getCopilotName();
		$newMsg = Loc::getMessage("ERROR_{$code}_MSGVER_1", $replacements) ?? Loc::getMessage("ERROR_{$code}", $replacements);
		if ($newMsg !== null)
		{
			return $newMsg;
		}

		return parent::loadErrorMessage($code, $replacements);
	}

	public const
		AI_MODULE_ERROR = 'AI_MODULE_ERROR',
		AI_UNAVAILABLE_ERROR = 'AI_UNAVAILABLE_ERROR',
		AI_SETTINGS_ERROR = 'AI_SETTINGS_ERROR',
		AI_AGREEMENT_ERROR = 'AI_AGREEMENT_ERROR',
		UNSUPPORTED_TRACK_ERROR = 'UNSUPPORTED_TRACK_ERROR',
		EMPTY_PAYLOAD_ERROR = 'EMPTY_PAYLOAD_ERROR',
		AI_NOT_ENOUGH_BAAS_ERROR = 'AI_NOT_ENOUGH_BAAS_ERROR',
		RECORD_TOO_SHORT = 'RECORD_TOO_SHORT',
		AI_TASK_START_FAIL = 'AI_TASK_START_FAIL',
		AI_TASK_FAILED = 'AI_TASK_FAILED',
		TRACK_PACK_NOT_FOUND_ERROR = 'TRACK_PACK_NOT_FOUND_ERROR',
		TRACK_PACK_NOT_FOUND_TIMEOUT = 'TRACK_PACK_NOT_FOUND_TIMEOUT',
		AI_TRANSCRIBE_TASK_ERROR = 'AI_TRANSCRIBE_TASK_ERROR',
		AI_OVERVIEW_TASK_ERROR = 'AI_OVERVIEW_TASK_ERROR',
		RECORDING_DISABLED = 'RECORDING_DISABLED',
		AI_MARKET_SUBSCRIPTION = 'AI_MARKET_SUBSCRIPTION',
		AI_EMPTY_RESPONSE_ERROR = 'AI_EMPTY_RESPONSE_ERROR',
		AI_INVALID_RESPONSE_ERROR = 'AI_INVALID_RESPONSE_ERROR',
		AI_UNEXPECTED_RESPONSE_STRUCTURE_ERROR = 'AI_UNEXPECTED_RESPONSE_STRUCTURE_ERROR'
	;

	protected bool $recoverable = false;

	/**
	 * Maps a media backend track error code onto a CallAIError.
	 *
	 * A "short recording" code maps to RECORD_TOO_SHORT; known mixer codes pass
	 * through as-is. The chat text is identical in both the pass-through and the
	 * fallback branch (mixer codes have no lang phrase, so both render the base
	 * failure message with no cause line); pass-through only preserves the real
	 * code as the FollowUpAnalytics event label instead of the generic
	 * 'TRACK_ERROR'. Anything else, including codes that would otherwise collide
	 * with the AI error family and leak a raw tech code into the chat, falls back
	 * to the generic code.
	 */
	public static function fromTrackErrorCode(string $code): self
	{
		if (str_contains(mb_strtoupper($code), 'SHORT'))
		{
			return new self(self::RECORD_TOO_SHORT);
		}

		// Local list on purpose: a class constant leaks into ReflectionClass::getConstants().
		$knownMixerCodes = [
			'ERR_MIXER_NOT_RESPONDING',
			'ERR_MIXER_INTERNAL',
			'ERR_NO_MIXER_AVAILABLE',
		];
		if (in_array($code, $knownMixerCodes, true))
		{
			return new self($code);
		}

		// Not a class constant on purpose: getErrorMessage() only surfaces
		// declared codes, so this stays a base-phrase fallback with no cause line.
		return new self('TRACK_ERROR');
	}

	/**
	 * Checks if error fired by ai module.
	 * @see \Bitrix\AI\Engine\Engine::onResponseError
	 */
	public function isAiGeneratedError(): bool
	{
		return
			str_starts_with($this->getCode(), 'AI_ENGINE_ERROR')
			|| str_starts_with($this->getCode(), 'LIMIT_IS_EXCEEDED')
			|| str_starts_with($this->getCode(), 'CLOUD_REGISTRATION')
			|| $this->getCode() === 'RATE_LIMIT'
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
			$innerCode = $processingError->getCode();
			if (!empty($innerCode))
			{
				$error->code = $innerCode;
			}
			$error->message = static::htmlToBbCodeLink($processingError->getMessage());
			$error->customData = $processingError->getCustomData();
		}

		if (
			$error->code !== 'HASH_EXPIRED'
			&& str_starts_with($error->code, 'AI_ENGINE_ERROR')
			&& ($errorRow = self::detectRowError($error->message, $processingTask))
		)
		{
			$error->description = $errorRow;
		}

		if (
			$error->isAiGeneratedError()
			|| $error->code !== 'HASH_EXPIRED'
		)
		{
			$error->allowRecover();
		}

		return $error;
	}

	public function recoverable(): bool
	{
		return $this->recoverable;
	}

	public function allowRecover(): self
	{
		$this->recoverable = true;
		return $this;
	}

	public function disallowRecover(): self
	{
		$this->recoverable = false;
		return $this;
	}

	private static function detectRowError(string $againstError, AITask $task): string
	{
		if (\Bitrix\Main\Loader::includeModule('ai'))
		{
			$payloadResult = $task->getAIPayload();
			if ($payloadResult->isSuccess())
			{
				/**
				 * @var \Bitrix\AI\Payload\IPayload $payload
				 */
				$payload = $payloadResult->getData()['payload'];
				//$context = $task->getAIEngineContext();
				//$engine = $task->getAIEngine($context);

				$history = \Bitrix\AI\Model\HistoryTable::getList([
					'filter' => [
						'=CONTEXT_MODULE' => 'call',
						'=CONTEXT_ID' => $task->getContextId(),
						'=ENGINE_CODE' => $task->getAIEngineCode(),
						//'=ENGINE_CLASS' => $engine::class,
						'=PAYLOAD_CLASS' => $payload::class,
						'>DATE_CREATE' => (new \Bitrix\Main\Type\DateTime())->add('-5sec'),
					],
					'order' => ['ID' => 'DESC'],
					'limit' => 1,
				]);
				if (
					($row = $history->fetch())
					&& !empty($row['RESULT_TEXT'])
					&& $row['RESULT_TEXT'] != $againstError
					&& !str_starts_with(ltrim($row['RESULT_TEXT']), '{')
				)
				{
					return static::htmlToBbCodeLink($row['RESULT_TEXT']);
				}
			}
		}

		return '';
	}
}
