<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\ProcessData;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\ProcessData\Dto\ProcessDataCatalog;
use Bitrix\Bizproc\Public\ProcessData\Dto\ProcessDataElement;
use Bitrix\Bizproc\Workflow\Template\SourceType;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class DataElementKeyValidator
{
	private const KEY_PATTERN = '#^[a-z0-9_]+$#Di';

	private const PAIR_MATCHED_PROPERTY_KEYS = ['Type', 'Multiple', 'Options'];

	private const BLOCK_NAME_PHRASE_PREFIX = 'BIZPROC_API_ENUM_ERRORS_PROCESS_DATA_BLOCK_';

	public const BLOCK_OF_SOURCE_TYPE = [
		SourceType::Parameter => 'PARAMETERS',
		SourceType::Variable => 'VARIABLES',
		SourceType::Constant => 'CONSTANTS',
	];

	/**
	 * @param string[] $blocks Blocks the key sits in, so a refusal can name the element it is about.
	 */
	public function validateKey(string $key, array $blocks = []): Result
	{
		$result = new Result();

		if (\CBPHelper::isEmptyValue($key) || !preg_match(self::KEY_PATTERN, $key))
		{
			$result->addError($this->invalidKeyError($key, $blocks));
		}

		return $result;
	}

	public function validateCatalog(ProcessDataCatalog $catalog): Result
	{
		$result = new Result();

		foreach ($this->groupByKey($catalog) as $elements)
		{
			$key = $elements[0]->key;

			$result->addErrors($this->validateKey($key, $this->selectBlocksOf($elements))->getErrors());

			if (count($elements) > 1)
			{
				$result->addError($this->duplicateKeyError($key, $this->blocksOf($elements)));
			}

			foreach ($elements as $element)
			{
				if ($element->acceptsStartValue && !$this->isConsistentPair($element))
				{
					$result->addError($this->pairMismatchError($element->key, $this->selectBlocksOf([$element])));
				}
			}
		}

		return $result;
	}

	/**
	 * Shared values live in their own namespace and never collide with the keys of the template blocks.
	 *
	 * @return array<string, ProcessDataElement[]> Elements of the three blocks by their case insensitive key.
	 */
	private function groupByKey(ProcessDataCatalog $catalog): array
	{
		$groups = [];

		foreach ($catalog->getElements() as $element)
		{
			if (isset(self::BLOCK_OF_SOURCE_TYPE[$element->sourceType]))
			{
				$groups[mb_strtolower($element->key)][] = $element;
			}
		}

		return $groups;
	}

	/**
	 * Blocks the elements themselves sit in, so a refusal points at the element and not at the half of the
	 * pair that only accepts the start value.
	 *
	 * @param ProcessDataElement[] $elements
	 * @return string[]
	 */
	private function selectBlocksOf(array $elements): array
	{
		return array_values(array_unique(array_map(
			static fn(ProcessDataElement $element) => self::BLOCK_OF_SOURCE_TYPE[$element->sourceType],
			$elements,
		)));
	}

	/**
	 * @param ProcessDataElement[] $elements
	 * @return string[]
	 */
	private function blocksOf(array $elements): array
	{
		$blocks = [];

		foreach ($elements as $element)
		{
			if ($element->acceptsStartValue)
			{
				$blocks[] = self::BLOCK_OF_SOURCE_TYPE[SourceType::Parameter];
			}

			$blocks[] = self::BLOCK_OF_SOURCE_TYPE[$element->sourceType];
		}

		return array_values(array_unique($blocks));
	}

	private function isConsistentPair(ProcessDataElement $element): bool
	{
		$workingHalf = FieldType::normalizeProperty($element->property);
		$inputHalf = FieldType::normalizeProperty($element->inputProperty);

		foreach (self::PAIR_MATCHED_PROPERTY_KEYS as $propertyKey)
		{
			$working = $workingHalf[$propertyKey];
			$input = $inputHalf[$propertyKey];

			if (\CBPHelper::isEmptyValue($working) && \CBPHelper::isEmptyValue($input))
			{
				continue;
			}

			if ($working !== $input)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string[] $blocks
	 */
	private function invalidKeyError(string $key, array $blocks = []): Error
	{
		return ErrorMessage::PROCESS_DATA_KEY_INVALID->getCodedError(
			['#KEY#' => $key],
			['key' => $key, 'blocks' => $blocks],
		);
	}

	/**
	 * @param string[] $blocks
	 */
	public function duplicateKeyError(string $key, array $blocks): Error
	{
		$errorMessage = ErrorMessage::PROCESS_DATA_KEY_DUPLICATE;

		return $errorMessage->getCodedError(
			['#KEY#' => $key, '#BLOCKS#' => $this->nameBlocks($blocks)],
			['key' => $key, 'blocks' => $blocks],
		);
	}

	/**
	 * @param string[] $blocks
	 */
	private function pairMismatchError(string $key, array $blocks = []): Error
	{
		return ErrorMessage::PROCESS_DATA_PAIR_MISMATCH->getCodedError(
			['#KEY#' => $key],
			['key' => $key, 'blocks' => $blocks],
		);
	}

	/**
	 * @param string[] $blocks
	 */
	private function nameBlocks(array $blocks): string
	{
		$names = array_map(
			static fn(string $block) => Loc::getMessage(self::BLOCK_NAME_PHRASE_PREFIX . $block) ?? $block,
			$blocks,
		);

		return implode(', ', $names);
	}
}
