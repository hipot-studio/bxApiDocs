<?php

namespace Bitrix\Mobile\Internal\Services\Project;

final class OperationResultErrorResolver
{
	public function resolve(object $result, string $defaultMessage): string
	{
		if (method_exists($result, 'getErrorMessages'))
		{
			$errorMessages = $result->getErrorMessages();
			if (!empty($errorMessages[0]))
			{
				return $errorMessages[0];
			}
		}

		if (method_exists($result, 'getErrors'))
		{
			$errors = $result->getErrors();
			$firstError = $errors[0] ?? null;
			if ($firstError && method_exists($firstError, 'getMessage'))
			{
				return $firstError->getMessage();
			}
		}

		return $defaultMessage;
	}
}
