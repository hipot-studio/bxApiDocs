<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\SystemHistoryLog;

use Bitrix\Tasks\Validation\Validator\SerializedValidator;

class ErrorsUnpackService
{
	public function unpackErrors(string $serializedErrors): ?array
	{
		$isSerialized = (new SerializedValidator())->validate($serializedErrors)->isSuccess();
		if (!$isSerialized)
		{
			return null;
		}

		$unserializedErrors = unserialize($serializedErrors, ['allowed_classes' => false]);

		if (!is_array($unserializedErrors))
		{
			return null;
		}

		$filteredErrors = [];

		foreach ($unserializedErrors as $error)
		{
			if (!is_array($error))
			{
				continue;
			}

			if (!$this->isErrorStructureValid($error))
			{
				continue;
			}

			$filteredErrors[] = $error;
		}

		return $filteredErrors;
	}

	private function isErrorStructureValid(array $error): bool
	{
		$allowedKeys = [
			'MESSAGE',
			'TYPE',
			'CODE',
		];

		foreach ($error as $key => $value)
		{
			if (
				!in_array($key, $allowedKeys, true)
				|| (!is_string($value) && !is_numeric($value))
			)
			{
				return false;
			}
		}

		return true;
	}
}
