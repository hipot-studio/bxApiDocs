<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Validation;

use Bitrix\Im\V2\Message\Builder\BuilderError;
use Bitrix\Im\V2\Message\Builder\Entity\Config;
use Bitrix\Im\V2\Message\Builder\Factory\FieldFactory;
use Bitrix\Im\V2\Result;

class ConfigValidationService
{
	public function __construct(
		protected FieldFactory $fieldFactory,
	)
	{}

	public function validate(array $configData): Result
	{
		$result = new Result();

		foreach (Config::getRequiredFields() as $field)
		{
			if (!array_key_exists($field, $configData))
			{
				return $result->addError((new BuilderError(BuilderError::EMPTY_REQUIRED_FIELD)));
			}
		}

		return $this->validateInternal($configData);
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
