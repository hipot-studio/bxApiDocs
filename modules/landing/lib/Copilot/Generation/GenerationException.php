<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation;

use Bitrix\Main\SystemException;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;

class GenerationException extends SystemException
{
	/**
	 * Array with error message templates.
	 */

	private ?array $params;

	/**
	 * Exception constructor.
	 *
	 * @param GenerationErrors $code Error code.
	 * @param string $message Additional message (optional).
	 */
	public function __construct(GenerationErrors $code, string $message = '', ?array $params = null)
	{
		$this->params = $params;
		$errorMessage = $this->buildErrorMessage($code, $message);

		parent::__construct($errorMessage, $code->value);
	}

	/**
	 * Get exception code transform to enum
	 * @return ?GenerationErrors
	 */
	public function getCodeObject(): ?GenerationErrors
	{
		return GenerationErrors::tryFrom($this->getCode()) ?? GenerationErrors::default;
	}

	/**
	 * @return array|null
	 */
	public function getParams(): ?array
	{
		return $this->params;
	}

	/**
	 * Receives an error message based on the code.
	 *
	 * @param GenerationErrors $code Error code DTO
	 * @param string $additionalMessage Additional message.
	 *
	 * @return string Error message.
	 */
	protected function buildErrorMessage(GenerationErrors $code, string $additionalMessage): string
	{
		$message = self::getErrorMessage($code);

		if ($additionalMessage !== '')
		{
			return $message . ': ' . $additionalMessage . '.';
		}

		return $message . '.';
	}

	private static function getErrorMessage(GenerationErrors $code): string
	{
		$messages = [
			GenerationErrors::default->value => 'Generation error',
			GenerationErrors::notExistResponse->value => 'Response is not exist',
			GenerationErrors::restrictedRequest->value => 'Request is not allowed',
			GenerationErrors::dataValidation->value => 'Data validation error',
			GenerationErrors::notFullyResponse->value => 'Response is not complete',
			GenerationErrors::notCorrectResponse->value => 'Response is not correct',
			GenerationErrors::requestQuotaExceeded->value => 'Not enough requests. Limit type',
			GenerationErrors::notSendRequest->value => 'Request is not send',
			GenerationErrors::errorInRequest->value => 'Error in request',
		];

		return $messages[$code->value] ?? $messages[GenerationErrors::default->value];
	}
}
