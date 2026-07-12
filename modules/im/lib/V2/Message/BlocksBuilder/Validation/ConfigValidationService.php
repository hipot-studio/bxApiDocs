<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Validation;

use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Message\BlocksBuilder\Factory\FieldFactory;
use Bitrix\Im\V2\Result;

class ConfigValidationService
{
	public function __construct(
		protected FieldFactory $fieldFactory,
	)
	{}

	public function validate(array $builderData): Result
	{
		$result = new Result();

		$configData = $builderData['config'] ?? [];
		if (!is_array($configData))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_CONFIG));
		}

		$validatedConfig = $this->validateInternal($configData);
		if (!$validatedConfig->isSuccess())
		{
			return $validatedConfig;
		}

		$builderData['config'] = $validatedConfig->getResult();

		return $result->setResult($builderData);
	}

	public function validateInternal(array $configData): Result
	{
		$result = new Result();

		foreach ($configData as $fieldName => $value)
		{
			$field = $this->fieldFactory->create($fieldName);
			if ($field === null)
			{
				continue;
			}

			$result = $field->validate($value);
			if (!$result->isSuccess())
			{
				return $result;
			}

			$configData[$fieldName] = $result->getResult();
		}

		return $result->setResult($configData);
	}
}
