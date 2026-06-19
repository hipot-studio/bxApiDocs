<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\Builder\BuilderError;
use Bitrix\Im\V2\Message\Builder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class Rows extends AbstractField
{
	protected const MAX_COLUMN_COUNT = 2;

	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		$result = new Result();

		if (!is_array($field))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_ROWS_FIELD));
		}

		if (empty($field))
		{
			return $result->addError(new BuilderError(BuilderError::EMPTY_ROWS_FIELD));
		}

		foreach ($field as $key => $row)
		{
			if (!is_array($row))
			{
				return $result->addError(new BuilderError(BuilderError::INVALID_ROWS_FIELD));
			}

			if (count($row) > self::MAX_COLUMN_COUNT)
			{
				return $result->addError(new BuilderError(BuilderError::MAX_COLUMN_COUNT));
			}

			$result = $this->validateRow($row);
			if (!$result->isSuccess())
			{
				return $result;
			}

			$field[$key] = $result->getResult();
		}

		return $result->setResult($field);
	}

	protected function validateRow(mixed $row): Result
	{
		$result = new Result();

		$columns = [];
		foreach ($row as $column)
		{
			if (!is_array($column))
			{
				return $result->addError(new BuilderError(BuilderError::INVALID_ROWS_FIELD));
			}

			$text = $column['text'] ?? null;
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

			$columns[] = ['text' => $text];
		}

		return $result->setResult($columns);
	}
}
