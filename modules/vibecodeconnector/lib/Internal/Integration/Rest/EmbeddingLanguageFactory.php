<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest;

use Bitrix\Rest\Public\Dto\Embedding\EmbeddingLanguageDto;
use Bitrix\Vibecodeconnector\Internal\Exception\EmbeddingOperationRejectedException;

final class EmbeddingLanguageFactory
{
	private const FIELD = 'languages';
	private const LANGUAGE_ID_MAX_LENGTH = 2;
	private const TEXT_MAX_LENGTH = 255;
	private const MAX_LANGUAGES = 32;

	/**
	 * @return list<EmbeddingLanguageDto>
	 */
	public function create(array $languages): array
	{
		if (count($languages) > self::MAX_LANGUAGES)
		{
			throw $this->reject('The number of languages must be at most ' . self::MAX_LANGUAGES);
		}

		$collection = [];
		$knownLanguageIds = [];

		foreach ($languages as $languageId => $texts)
		{
			$languageId = $this->getLanguageId($languageId);
			if (isset($knownLanguageIds[$languageId]))
			{
				throw $this->reject('The language is listed twice: ' . $languageId);
			}

			$knownLanguageIds[$languageId] = true;

			if (!is_array($texts))
			{
				throw $this->reject('The texts of the language must be an object: ' . $languageId);
			}

			$title = $this->getText($texts, 'title');
			if ($title === '')
			{
				throw $this->reject('The title of the language must not be empty: ' . $languageId);
			}

			$collection[] = new EmbeddingLanguageDto(
				languageId: $languageId,
				title: $title,
				description: $this->getText($texts, 'description'),
				groupName: $this->getText($texts, 'groupName'),
			);
		}

		return $collection;
	}

	private function getLanguageId(mixed $languageId): string
	{
		if (!is_string($languageId))
		{
			throw $this->reject('The language id must be a string');
		}

		$languageId = mb_strtolower(trim($languageId));
		if ($languageId === '' || mb_strlen($languageId) > self::LANGUAGE_ID_MAX_LENGTH)
		{
			throw $this->reject('The language id must be a string of one or two characters');
		}

		return $languageId;
	}

	private function getText(array $texts, string $key): string
	{
		$value = $texts[$key] ?? null;
		if ($value === null)
		{
			return '';
		}

		if (!is_string($value))
		{
			throw $this->reject('The embedding text must be a string: ' . $key);
		}

		$value = trim($value);
		if (mb_strlen($value) > self::TEXT_MAX_LENGTH)
		{
			throw $this->reject(
				'The embedding text must be at most ' . self::TEXT_MAX_LENGTH . ' characters long: ' . $key,
			);
		}

		return $value;
	}

	private function reject(string $message): EmbeddingOperationRejectedException
	{
		return new EmbeddingOperationRejectedException($message, field: self::FIELD);
	}
}
