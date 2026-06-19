<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\Builder\BuilderError;
use Bitrix\Im\V2\Message\Builder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class Elements extends AbstractField
{
	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		$result = new Result();

		if (!is_array($field))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_ELEMENTS_FIELD));
		}

		if (empty($field))
		{
			return $result->addError(new BuilderError(BuilderError::EMPTY_ELEMENTS_FIELD));
		}

		foreach ($field as $key => $element)
		{
			if (!is_array($element))
			{
				return $result->addError(new BuilderError(BuilderError::INVALID_ELEMENTS_FIELD));
			}

			$result = $this->validateElement($element);
			if (!$result->isSuccess())
			{
				return $result;
			}

			$field[$key] = $result->getResult();
		}

		return $result->setResult($field);
	}

	protected function validateElement(mixed $element): Result
	{
		$result = new Result();

		$text = $element['text'] ?? null;
		if ($text === null)
		{
			return $result->addError(new BuilderError(BuilderError::EMPTY_REQUIRED_FIELD));
		}

		if (!is_string($text))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_TEXT_FIELD));
		}

		if (mb_strlen($text) > self::MAX_TEXT_LENGTH)
		{
			return $result->addError(new BuilderError(BuilderError::MAX_TEXT_LENGTH));
		}

		return $result->setResult(['text' => $text]);
	}
}
