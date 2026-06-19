<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Validation;

use Bitrix\Im\V2\Message\Builder\BuilderError;
use Bitrix\Im\V2\Message\Builder\Entity\BlockType;
use Bitrix\Im\V2\Message\Builder\Factory\BlockFactory;
use Bitrix\Im\V2\Message\Builder\Factory\FieldFactory;
use Bitrix\Im\V2\Result;

class BlockValidationService
{
	public function __construct(
		protected BlockFactory $blockFactory,
		protected FieldFactory $fieldFactory,
	)
	{}

	public function validate(array $blockData): Result
	{
		$blockType = $blockData['type'] ?? '';

		foreach ($this->blockFactory->getRequiredFields($blockType) as $field)
		{
			if (!array_key_exists($field, $blockData))
			{
				return (new Result())->addError((new BuilderError(BuilderError::EMPTY_REQUIRED_FIELD)));
			}
		}

		return $this->validateInternal($blockData);
	}

	public function validateInternal(array $blockData): Result
	{
		$result = new Result();

		$blockType = BlockType::tryFrom($blockData['type'] ?? '');
		if ($blockType === null)
		{
			return $result->addError(new BuilderError(BuilderError::WRONG_BLOCK_TYPE));
		}

		foreach ($blockData as $fieldName => $value)
		{
			$field = $this->fieldFactory->create($fieldName);
			if ($field === null)
			{
				continue;
			}

			$result = $field->validate($value, $blockType);
			if (!$result->isSuccess())
			{
				return $result;
			}

			$blockData[$fieldName] = $result->getResult();
		}

		return $result->setResult($blockData);
	}
}
