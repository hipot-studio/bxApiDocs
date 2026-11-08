<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * A refused builder operation in the shape toMcpException() reads: the structural part of the refusal,
 * which an operation keeps in the result data, goes back into the custom data of the error, where the
 * helpers of the previous path put it. The agent gets the same text on either path.
 */
final class OperationRefusal
{
	public static function forTool(Result $refused): Result
	{
		$error = $refused->getErrors()[0] ?? null;
		$payload = $refused->getData();
		if ($error === null || $payload === [])
		{
			return $refused;
		}

		return (new Result())->addError(new Error($error->getMessage(), $error->getCode(), $payload));
	}
}
