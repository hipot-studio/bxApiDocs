<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class Sources extends AbstractField
{
	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		$result = new Result();

		if (empty($field))
		{
			return $result->setResult([]);
		}

		if (!is_array($field))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_SOURCES_FIELD));
		}

		foreach ($field as $key => $source)
		{
			if (!is_array($source))
			{
				return $result->addError(new BuilderError(BuilderError::INVALID_SOURCES_FIELD));
			}

			if (!is_int($key))
			{
				return $result->addError(new BuilderError(BuilderError::INVALID_CODE_SOURCES));
			}

			$result = $this->validateSource($source);
			if (!$result->isSuccess())
			{
				return $result;
			}

			$field[$key] = $result->getResult();
		}

		return $result->setResult($field);
	}

	protected function validateSource(mixed $source): Result
	{
		$result = new Result();

		if (!is_array($source))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_SOURCES_FIELD));
		}

		$url = $source['url'] ?? null;

		if (!is_string($url))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_URL_FIELD));
		}
		if ($url === '')
		{
			return $result->addError(new BuilderError(BuilderError::EMPTY_URL_FIELD));
		}
		if (!$this->isAllowedUrl($url))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_URL_FIELD));
		}

		$result = $this->validateMetaData($source['metaData'] ?? null);
		$metaData = $result->getResult();

		return $result->setResult([
			'url' => $url,
			'metaData' => $metaData ?? [],
		]);
	}

	protected function validateMetaData(mixed $metaData): Result
	{
		$result = new Result();

		if (empty($metaData) || !is_array($metaData))
		{
			return $result;
		}

		$title = $this->validateTitle($metaData['title'] ?? null);
		$description = $this->validateDescription($metaData['description'] ?? null);

		return $result->setResult([
			'title' => $title,
			'description' => $description
		]);
	}

	protected function validateTitle(mixed $title): ?string
	{
		if (!is_string($title) || $title === '')
		{
			return null;
		}

		return $title;
	}

	protected function validateDescription(mixed $description): ?string
	{
		if (!is_string($description) || $description === '')
		{
			return null;
		}

		return $description;
	}
}
