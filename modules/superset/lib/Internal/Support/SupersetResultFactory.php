<?php

namespace Bitrix\Superset\Internal\Support;

use Bitrix\Main;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\RequestResult;
use Bitrix\Superset\Internal\Connector\SupersetInstance;

final class SupersetResultFactory
{
	public function createErrorResult(
		string $message,
		?RequestResult $requestResult = null,
		?int $httpStatus = null
	): Main\Result
	{
		$result = new Main\Result();
		$errorCode = '';
		if ($httpStatus !== null)
		{
			$errorCode = (string)$httpStatus;
		}
		elseif (
			$requestResult !== null
			&& $requestResult->getHttpStatus() >= HttpStatus::BAD_REQUEST
		)
		{
			$errorCode = (string)$requestResult->getHttpStatus();
		}

		$result->addError(new Main\Error(
			$message,
			$errorCode
		));

		if ($requestResult !== null)
		{
			$result->setData([
				'requestResult' => $requestResult,
			]);
		}

		return $result;
	}

	public function createRequestErrorResult(
		RequestResult $requestResult,
		string $fallbackMessage
	): Main\Result
	{
		return $this->createErrorResult(
			$this->extractRequestErrorMessage($requestResult, $fallbackMessage),
			$requestResult
		);
	}

	private function extractRequestErrorMessage(
		RequestResult $requestResult,
		string $fallbackMessage
	): string
	{
		$answer = $requestResult->getAnswer();
		if ($answer !== '')
		{
			return SupersetInstance::buildErrorMessageFromBody($answer);
		}

		$errorMessages = $requestResult->getErrorMessages();
		if (!empty($errorMessages))
		{
			return implode('; ', $errorMessages);
		}

		return $fallbackMessage;
	}
}
