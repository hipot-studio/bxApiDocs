<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\ProcessData\Service;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Service\ProcessData\DataElementKeyValidator;
use Bitrix\Bizproc\Internal\Service\ProcessData\DataElementTypeValidator;
use Bitrix\Bizproc\Internal\Service\ProcessData\ProcessDataFeature;
use Bitrix\Bizproc\Public\ProcessData\Dto\ProcessDataCatalog;
use Bitrix\Bizproc\Public\ProcessData\Dto\ProcessDataElement;
use Bitrix\Bizproc\Public\ProcessData\Enum\DataElementPurpose;
use Bitrix\Bizproc\Public\Service\Template\ActivityUsageAnalyzer;
use Bitrix\Bizproc\Workflow\Template\SourceType;
use Bitrix\Main\Result;

/**
 * Data elements of a template, written back into its blocks. Nothing reaches the database: the fields of the
 * result go to the client and are stored by the usual draft autosave or publication, so the freeze of the
 * settings and every other rule of the save path keeps standing.
 */
final class ProcessDataWriteService
{
	private const BLOCKS = ['PARAMETERS', 'VARIABLES', 'CONSTANTS'];

	private const BLOCK_OF_SOURCE_TYPE = [
		SourceType::Parameter => 'PARAMETERS',
		SourceType::Variable => 'VARIABLES',
		SourceType::Constant => 'CONSTANTS',
	];

	/** The historic name of a parameter reference: the engine reads it as {@see SourceType::Parameter}. */
	private const PARAMETER_REFERENCE_SYNONYM = 'Template';

	private const PRINTABLE_FIELD_SUFFIX = '_printable';

	private readonly ProcessDataCatalogService $catalogService;

	private readonly DataElementKeyValidator $keyValidator;

	/**
	 * @param array $fields TEMPLATE, PARAMETERS, VARIABLES, CONSTANTS, TYPE, TEMPLATE_SETTINGS
	 */
	public function __construct(private readonly array $fields, private readonly int $templateId = 0)
	{
		$this->catalogService = new ProcessDataCatalogService();
		$this->keyValidator = new DataElementKeyValidator();
	}

	public function create(string $key, DataElementPurpose $purpose, array $property): Result
	{
		$refusal = $this->guardMechanics();
		if ($refusal !== null)
		{
			return $refusal;
		}

		$block = $this->blockOfPurpose($purpose);
		if ($block === null)
		{
			return $this->refuseUnsupportedPurpose($purpose);
		}

		$keyValidation = $this->keyValidator->validateKey($key);
		if (!$keyValidation->isSuccess())
		{
			return $keyValidation;
		}

		$fields = $this->fields;

		$refusal = $this->guardKeyIsFree($fields, self::BLOCKS, $key);
		if ($refusal !== null)
		{
			return $refusal;
		}

		$normalized = $this->normalizeProperty($property, null);

		$refusal = $this->guardType($normalized['Type'], null);
		if ($refusal !== null)
		{
			return $refusal;
		}

		$fields[$block][$key] = $normalized;

		return $this->finish($fields);
	}

	public function update(string $sourceType, string $key, array $property): Result
	{
		$refusal = $this->guardMechanics();
		if ($refusal !== null)
		{
			return $refusal;
		}

		$element = $this->findElement($sourceType, $key);
		if ($element === null)
		{
			return $this->refuseUnknownElement($key);
		}

		$fields = $this->fields;
		$block = self::BLOCK_OF_SOURCE_TYPE[$element->sourceType];
		$storedKey = $this->findStoredKey($fields, $block, $element->key) ?? $element->key;

		$storedType = FieldType::normalizeProperty($element->property)['Type'];
		$normalized = $this->normalizeProperty($property, $storedType);

		$refusal = $this->guardType($normalized['Type'], $storedType);
		if ($refusal !== null)
		{
			return $refusal;
		}

		$fields[$block][$storedKey] = $normalized;

		if ($element->acceptsStartValue)
		{
			$fields = $this->matchInputHalf($fields, $element->key);
		}

		return $this->finish($fields);
	}

	public function rename(string $sourceType, string $key, string $newKey): Result
	{
		$refusal = $this->guardMechanics();
		if ($refusal !== null)
		{
			return $refusal;
		}

		$element = $this->findElement($sourceType, $key);
		if ($element === null)
		{
			return $this->refuseUnknownElement($key);
		}

		$keyValidation = $this->keyValidator->validateKey($newKey);
		if (!$keyValidation->isSuccess())
		{
			return $keyValidation;
		}

		$fields = $this->fields;
		$blocks = $this->blocksOfElement($element);

		$refusal = $this->guardKeyIsFree($fields, self::BLOCKS, $newKey, $element->key);
		if ($refusal !== null)
		{
			return $refusal;
		}

		foreach ($blocks as $block)
		{
			$fields[$block] = $this->replaceKey($this->blockOf($fields, $block), $element->key, $newKey);
		}

		$fields = $this->rewriteReferences($fields, $this->referenceObjectsOf($element), $element->key, $newKey);

		$fields['TEMPLATE'] = $this->rewriteWriterTargets(
			$this->templateActivities($fields),
			$element->sourceType,
			$element->key,
			$newKey,
		);

		return $this->finish($fields);
	}

	public function changePurpose(string $sourceType, string $key, DataElementPurpose $purpose): Result
	{
		$refusal = $this->guardMechanics();
		if ($refusal !== null)
		{
			return $refusal;
		}

		$element = $this->findElement($sourceType, $key);
		if ($element === null)
		{
			return $this->refuseUnknownElement($key);
		}

		return match ($purpose)
		{
			DataElementPurpose::Input => $this->publish($element),
			DataElementPurpose::Internal => $this->withdraw($element),
			DataElementPurpose::Constant => $this->makeConstant($element),
			DataElementPurpose::Shared => $this->refuseUnsupportedPurpose($purpose),
		};
	}

	/**
	 * Move of an element into a shared value of the portal: the element leaves the blocks and its references
	 * answer to the shared source under the same key. The shared value itself is written by the caller, since
	 * it belongs to the portal and not to the template (API-04).
	 */
	public function moveToShared(string $sourceType, string $key, string $sharedSourceType): Result
	{
		$refusal = $this->guardMechanics();
		if ($refusal !== null)
		{
			return $refusal;
		}

		$element = $this->findElement($sourceType, $key);
		if ($element === null)
		{
			return $this->refuseUnknownElement($key);
		}

		// The shared value enters the portal now, so its identifier is held to the rule a new one is held to:
		// a key of a legacy element the form never offered does not become a value of the portal.
		$keyValidation = $this->keyValidator->validateKey($element->key);
		if (!$keyValidation->isSuccess())
		{
			return $keyValidation;
		}

		$writers = $this->findWriters($element->sourceType, $element->key);
		if ($writers !== [])
		{
			return $this->refuse(ErrorMessage::PROCESS_DATA_HAS_WRITERS, $element->key, $writers);
		}

		$fields = $this->rewriteReferences(
			$this->removeElement($this->fields, $element),
			$this->referenceObjectsOf($element),
			$element->key,
			$element->key,
			$sharedSourceType,
		);

		return $this->finish($fields);
	}

	/**
	 * Move of a shared value into the blocks of the template: the value is written under its own key and the
	 * references answer to the copy. The shared value itself belongs to the portal and is removed by the
	 * caller, once the template has taken it.
	 */
	public function moveFromShared(
		string $sharedSourceType,
		string $key,
		DataElementPurpose $purpose,
		array $property,
	): Result
	{
		$refusal = $this->guardMechanics();
		if ($refusal !== null)
		{
			return $refusal;
		}

		$block = $this->blockOfPurpose($purpose);
		if ($block === null)
		{
			return $this->refuseUnsupportedPurpose($purpose);
		}

		$keyValidation = $this->keyValidator->validateKey($key);
		if (!$keyValidation->isSuccess())
		{
			return $keyValidation;
		}

		// A writer names its target and no rewriting of references reaches it, so the value would keep being
		// written into the shared one the caller is about to remove from the portal.
		$writers = $this->findWriters($sharedSourceType, $key);
		if ($writers !== [])
		{
			return $this->refuse(ErrorMessage::PROCESS_DATA_SHARED_HAS_WRITERS, $key, $writers);
		}

		$fields = $this->fields;

		$refusal = $this->guardKeyIsFree($fields, self::BLOCKS, $key);
		if ($refusal !== null)
		{
			return $refusal;
		}

		$normalized = $this->normalizeProperty($property, null);

		$refusal = $this->guardType($normalized['Type'], null);
		if ($refusal !== null)
		{
			return $refusal;
		}

		$fields[$block][$key] = $normalized;

		$fields = $this->rewriteReferences(
			$fields,
			[mb_strtolower($sharedSourceType)],
			$key,
			$key,
			$this->sourceTypeOfBlock($block),
		);

		return $this->finish($fields);
	}

	public function delete(string $sourceType, string $key): Result
	{
		$refusal = $this->guardMechanics();
		if ($refusal !== null)
		{
			return $refusal;
		}

		$element = $this->findElement($sourceType, $key);
		if ($element === null)
		{
			return $this->refuseUnknownElement($key);
		}

		$usages = $this->findUsages($element->sourceType, $element->key);
		if ($usages !== [])
		{
			return $this->refuse(ErrorMessage::PROCESS_DATA_IN_USE, $element->key, $usages);
		}

		return $this->finish($this->removeElement($this->fields, $element));
	}

	/**
	 * Publication of an element: an element nodes write into keeps its working half and gains an input one
	 * (SC-004), any other element moves into the parameters.
	 */
	private function publish(ProcessDataElement $element): Result
	{
		if ($element->acceptsStartValue || $element->sourceType === SourceType::Parameter)
		{
			return $this->finish($this->fields);
		}

		$writers = $this->findWriters($element->sourceType, $element->key);

		if ($writers === [])
		{
			return $this->moveToBlock($element, 'PARAMETERS');
		}

		if ($element->sourceType !== SourceType::Variable)
		{
			return $this->refuse(ErrorMessage::PROCESS_DATA_HAS_WRITERS, $element->key, $writers);
		}

		$fields = $this->fields;

		$refusal = $this->guardKeyIsFree($fields, ['PARAMETERS'], $element->key);
		if ($refusal !== null)
		{
			return $refusal;
		}

		$fields['PARAMETERS'][$element->key] = $element->property;

		return $this->finish($fields);
	}

	/** Withdrawal of a publication: the input half of a pair goes away, a plain parameter becomes a variable. */
	private function withdraw(ProcessDataElement $element): Result
	{
		if ($element->acceptsStartValue)
		{
			$refusal = $this->guardInputHalfIsFree($element->key);
			if ($refusal !== null)
			{
				return $refusal;
			}

			$fields = $this->fields;
			$fields['PARAMETERS'] = $this->removeKey($this->blockOf($fields, 'PARAMETERS'), $element->key);

			return $this->finish($fields);
		}

		if ($element->sourceType === SourceType::Variable)
		{
			return $this->finish($this->fields);
		}

		return $this->moveToBlock($element, 'VARIABLES');
	}

	private function makeConstant(ProcessDataElement $element): Result
	{
		if ($element->sourceType === SourceType::Constant)
		{
			return $this->finish($this->fields);
		}

		$writers = $this->findWriters($element->sourceType, $element->key);
		if ($writers !== [])
		{
			return $this->refuse(ErrorMessage::PROCESS_DATA_HAS_WRITERS, $element->key, $writers);
		}

		if ($element->acceptsStartValue)
		{
			$refusal = $this->guardInputHalfIsFree($element->key);
			if ($refusal !== null)
			{
				return $refusal;
			}
		}

		return $this->moveToBlock($element, 'CONSTANTS');
	}

	private function moveToBlock(ProcessDataElement $element, string $targetBlock): Result
	{
		$fields = $this->removeElement($this->fields, $element);

		$refusal = $this->guardKeyIsFree($fields, self::BLOCKS, $element->key);
		if ($refusal !== null)
		{
			return $refusal;
		}

		$fields[$targetBlock][$element->key] = $element->property;

		$fields = $this->rewriteReferences(
			$fields,
			$this->referenceObjectsOf($element),
			$element->key,
			$element->key,
			$this->sourceTypeOfBlock($targetBlock),
		);

		return $this->finish($fields);
	}

	/** References of the withdrawn input half stay unresolvable, so they refuse the withdrawal (AC-028). */
	private function guardInputHalfIsFree(string $key): ?Result
	{
		$usages = $this->findUsages(SourceType::Parameter, $key);

		return $usages === [] ? null : $this->refuse(ErrorMessage::PROCESS_DATA_IN_USE, $key, $usages);
	}

	private function finish(array $fields): Result
	{
		$result = new Result();
		$result->addErrors($this->keyValidator->validateCatalog($this->catalogOf($fields))->getErrors());

		if ($result->isSuccess())
		{
			$result->setData([
				'TEMPLATE' => $this->templateActivities($fields),
				'PARAMETERS' => $this->blockOf($fields, 'PARAMETERS'),
				'VARIABLES' => $this->blockOf($fields, 'VARIABLES'),
				'CONSTANTS' => $this->blockOf($fields, 'CONSTANTS'),
			]);
		}

		return $result;
	}

	private function guardMechanics(): ?Result
	{
		if (
			ProcessDataFeature::isEnabled()
			&& (new ProcessDataMechanicsResolver())->isNewMechanicsForFields($this->fields, $this->templateId)
		)
		{
			return null;
		}

		$result = new Result();
		$result->addError(ErrorMessage::PROCESS_DATA_LEGACY_MECHANICS->getCodedError());

		return $result;
	}

	/**
	 * A key already standing in one of the blocks would be overwritten without a trace, and a key standing in
	 * both `PARAMETERS` and `VARIABLES` is read as a pair, so the start would write the one element over the
	 * other. Both are refused instead: the catalog of the future state sees one element and reports neither.
	 *
	 * @param string[] $blocks
	 * @param string $currentKey The key the element stands under now, so a rename is not refused by itself.
	 */
	private function guardKeyIsFree(array $fields, array $blocks, string $key, string $currentKey = ''): ?Result
	{
		$occupied = [];

		foreach ($blocks as $block)
		{
			$storedKey = $this->findStoredKey($fields, $block, $key);

			if ($storedKey !== null && !$this->isSameKey($storedKey, $currentKey))
			{
				$occupied[] = $block;
			}
		}

		if ($occupied === [])
		{
			return null;
		}

		$result = new Result();
		$result->addError($this->keyValidator->duplicateKeyError($key, $occupied));

		return $result;
	}

	/**
	 * @param array<int, array{activityName: string, title: string}> $activities
	 */
	private function refuse(ErrorMessage $errorMessage, string $key, array $activities): Result
	{
		$result = new Result();
		$result->addError(
			$errorMessage->getCodedError(['#KEY#' => $key], ['key' => $key, 'activities' => $activities])
		);

		return $result;
	}

	private function refuseUnknownElement(string $key): Result
	{
		$result = new Result();
		$result->addError(ErrorMessage::ENTITY_NOT_EXISTS->getCodedError([], ['key' => $key]));

		return $result;
	}

	/**
	 * The property of an element is written in the shape the engine reads, and an unnamed type stays what it
	 * already was: a property described otherwise is a property the legacy designer would not have written.
	 */
	private function normalizeProperty(array $property, ?string $storedType): array
	{
		$normalized = FieldType::normalizeProperty($property);
		$normalized['Type'] ??= $storedType ?? FieldType::STRING;

		return $normalized;
	}

	private function guardType(string $type, ?string $storedType): ?Result
	{
		$validation = (new DataElementTypeValidator())->validateType($type, $storedType);

		return $validation->isSuccess() ? null : $validation;
	}

	private function refuseUnsupportedPurpose(DataElementPurpose $purpose): Result
	{
		$result = new Result();
		$result->addError(
			ErrorMessage::INVALID_PARAM_ARG->getCodedError(
				['#PARAM#' => 'purpose', '#VALUE#' => $purpose->value],
				['purpose' => $purpose->value],
			)
		);

		return $result;
	}

	private function findElement(string $sourceType, string $key): ?ProcessDataElement
	{
		return $this->catalogOf($this->fields)->findByKey($key, $sourceType);
	}

	private function catalogOf(array $fields): ProcessDataCatalog
	{
		return $this->catalogService->readByBlocks(
			$this->blockOf($fields, 'PARAMETERS'),
			$this->blockOf($fields, 'VARIABLES'),
			$this->blockOf($fields, 'CONSTANTS'),
		);
	}

	/**
	 * Blocks holding the element: a pair stands in two of them and is written and removed as a whole
	 * (ADR invariant 2).
	 *
	 * @return string[]
	 */
	private function blocksOfElement(ProcessDataElement $element): array
	{
		$blocks = [self::BLOCK_OF_SOURCE_TYPE[$element->sourceType]];

		if ($element->acceptsStartValue)
		{
			$blocks[] = 'PARAMETERS';
		}

		return $blocks;
	}

	private function removeElement(array $fields, ProcessDataElement $element): array
	{
		foreach ($this->blocksOfElement($element) as $block)
		{
			$fields[$block] = $this->removeKey($this->blockOf($fields, $block), $element->key);
		}

		return $fields;
	}

	/**
	 * Names a reference to the element may carry. A pair answers to both halves, and a parameter also to
	 * the historic name of its source.
	 *
	 * @return string[] Lower cased names.
	 */
	private function referenceObjectsOf(ProcessDataElement $element): array
	{
		$objects = [$element->sourceType];

		if ($element->acceptsStartValue || $element->sourceType === SourceType::Parameter)
		{
			$objects[] = SourceType::Parameter;
			$objects[] = self::PARAMETER_REFERENCE_SYNONYM;
		}

		return array_values(array_unique(array_map('mb_strtolower', $objects)));
	}

	/**
	 * @param string[] $objects Lower cased names of the source the reference is written with.
	 * @param string|null $targetObject The source a moved element answers to now; null keeps the name as
	 *     written, so both halves of a pair survive a rename.
	 */
	private function rewriteReferences(
		array $fields,
		array $objects,
		string $key,
		string $targetKey,
		?string $targetObject = null,
	): array
	{
		$rewrite = fn(mixed $value): mixed => $this->rewriteValue($value, $objects, $key, $targetKey, $targetObject);

		$fields['TEMPLATE'] = $rewrite($this->templateActivities($fields));

		foreach (self::BLOCKS as $block)
		{
			foreach ($this->blockOf($fields, $block) as $elementKey => $property)
			{
				if (is_array($property) && array_key_exists('Default', $property))
				{
					$fields[$block][$elementKey]['Default'] = $rewrite($property['Default']);
				}
			}
		}

		return $fields;
	}

	private function rewriteValue(
		mixed $value,
		array $objects,
		string $key,
		string $targetKey,
		?string $targetObject,
	): mixed
	{
		if (is_array($value))
		{
			foreach ($value as $index => $item)
			{
				$value[$index] = $this->rewriteValue($item, $objects, $key, $targetKey, $targetObject);
			}

			return $value;
		}

		if (!is_string($value))
		{
			return $value;
		}

		return preg_replace_callback(
			\CBPActivity::ValueInlinePattern,
			fn(array $matches): string => $this->rewriteReference($matches, $objects, $key, $targetKey, $targetObject),
			$value,
		);
	}

	/**
	 * An inline reference stands both on its own and inside a calculated expression, so the same rewrite
	 * covers `{=Source:Key}` and `{{=...}}`. Modifiers and the printable suffix are kept.
	 */
	private function rewriteReference(
		array $matches,
		array $objects,
		string $key,
		string $targetKey,
		?string $targetObject,
	): string
	{
		$object = (string)$matches['object'];
		$field = (string)$matches['field'];
		$suffix = '';

		if ($this->isPrintableField($field))
		{
			$suffix = mb_substr($field, -mb_strlen(self::PRINTABLE_FIELD_SUFFIX));
			$field = mb_substr($field, 0, -mb_strlen(self::PRINTABLE_FIELD_SUFFIX));
		}

		if (!in_array(mb_strtolower($object), $objects, true) || !$this->isSameKey($field, $key))
		{
			return (string)$matches[0];
		}

		$modifier = '';
		if (($matches['mod1'] ?? '') !== '')
		{
			$modifier = ' > ' . $matches['mod1'];

			if (($matches['mod2'] ?? '') !== '')
			{
				$modifier .= ', ' . $matches['mod2'];
			}
		}

		return '{=' . ($targetObject ?? $object) . ':' . $targetKey . $suffix . $modifier . '}';
	}

	private function isPrintableField(string $field): bool
	{
		$suffixLength = mb_strlen(self::PRINTABLE_FIELD_SUFFIX);

		return mb_strlen($field) > $suffixLength
			&& mb_strtolower(mb_substr($field, -$suffixLength)) === self::PRINTABLE_FIELD_SUFFIX
		;
	}

	/**
	 * Nodes writing a value into the element (Q-6). A writer of another source under the same key is not one
	 * of them: the shared value and the variable of the template live in namespaces of their own.
	 *
	 * @return array<int, array{activityName: string, title: string}>
	 */
	private function findWriters(string $sourceType, string $key): array
	{
		$writers = [];

		foreach (ActivityUsageAnalyzer::collectTemplateActivities($this->templateActivities()) as $activity)
		{
			foreach (ActivityWriteTargets::of($activity) as $target)
			{
				if ($target['sourceType'] === $sourceType && $this->isSameKey($target['key'], $key))
				{
					$writers[] = [
						'activityName' => (string)($activity['Name'] ?? ''),
						'title' => (string)($activity['Properties']['Title'] ?? ''),
					];

					break;
				}
			}
		}

		return $writers;
	}

	/**
	 * A writer names its element by the key itself and not by an expression (Q-6), so a rename reaches it
	 * only here.
	 */
	private function rewriteWriterTargets(
		array $activities,
		string $sourceType,
		string $key,
		string $newKey,
	): array
	{
		foreach ($activities as $index => $activity)
		{
			if (!is_array($activity))
			{
				continue;
			}

			$writerProperty = ActivityWriteTargets::propertyOf((string)($activity['Type'] ?? ''), $sourceType);

			if ($writerProperty !== null && isset($activity['Properties'][$writerProperty]))
			{
				$activity['Properties'][$writerProperty] = $this->renameWrittenKey(
					$activity['Properties'][$writerProperty],
					$key,
					$newKey,
				);
			}

			if (is_array($activity['Children'] ?? null))
			{
				$activity['Children'] = $this->rewriteWriterTargets(
					$activity['Children'],
					$sourceType,
					$key,
					$newKey,
				);
			}

			$activities[$index] = $activity;
		}

		return $activities;
	}

	/**
	 * A written key stands either in the `Name` of the row or, when the row has none, in the key of the row
	 * itself - the same pair {@see ActivityWriteTargets::of()} reads.
	 */
	private function renameWrittenKey(mixed $writerProperty, string $key, string $newKey): mixed
	{
		if (!is_array($writerProperty))
		{
			return $writerProperty;
		}

		$renamed = [];

		foreach ($writerProperty as $rowKey => $row)
		{
			$name = is_array($row) ? ($row['Name'] ?? null) : null;

			if (is_string($name) && !\CBPHelper::isEmptyValue($name))
			{
				if ($this->isSameKey($name, $key))
				{
					$row['Name'] = $newKey;
				}

				$renamed[$rowKey] = $row;

				continue;
			}

			$renamed[$this->isSameKey((string)$rowKey, $key) ? $newKey : $rowKey] = $row;
		}

		return $renamed;
	}

	/**
	 * @return array<int, array{activityName: string, title: string}>
	 */
	private function findUsages(string $sourceType, string $key): array
	{
		return $this->catalogService->findUsages(
			$this->templateActivities(),
			$sourceType,
			$key,
			$this->blockOf($this->fields, 'PARAMETERS'),
			$this->blockOf($this->fields, 'VARIABLES'),
			$this->blockOf($this->fields, 'CONSTANTS'),
		);
	}

	/**
	 * The halves of a pair describe one value under one key, so the working half sets the whole description
	 * of the input one — the name and the hint the start form shows, its obligation and its default value
	 * exactly as the type of the value.
	 */
	private function matchInputHalf(array $fields, string $key): array
	{
		$inputKey = $this->findStoredKey($fields, 'PARAMETERS', $key);
		$workingKey = $this->findStoredKey($fields, 'VARIABLES', $key);
		$workingProperty = $workingKey === null ? null : ($this->blockOf($fields, 'VARIABLES')[$workingKey] ?? null);

		if ($inputKey === null || !is_array($workingProperty))
		{
			return $fields;
		}

		$fields['PARAMETERS'][$inputKey] = $workingProperty;

		return $fields;
	}

	private function replaceKey(array $block, string $key, string $newKey): array
	{
		$replaced = [];

		foreach ($block as $storedKey => $property)
		{
			if ($this->isSameKey((string)$storedKey, $key))
			{
				$replaced[$newKey] = $property;

				continue;
			}

			$replaced[$storedKey] = $property;
		}

		return $replaced;
	}

	private function removeKey(array $block, string $key): array
	{
		foreach (array_keys($block) as $storedKey)
		{
			if ($this->isSameKey((string)$storedKey, $key))
			{
				unset($block[$storedKey]);
			}
		}

		return $block;
	}

	private function findStoredKey(array $fields, string $block, string $key): ?string
	{
		foreach (array_keys($this->blockOf($fields, $block)) as $storedKey)
		{
			if ($this->isSameKey((string)$storedKey, $key))
			{
				return (string)$storedKey;
			}
		}

		return null;
	}

	private function blockOf(array $fields, string $block): array
	{
		return is_array($fields[$block] ?? null) ? $fields[$block] : [];
	}

	private function templateActivities(?array $fields = null): array
	{
		$fields ??= $this->fields;

		return is_array($fields['TEMPLATE'] ?? null) ? $fields['TEMPLATE'] : [];
	}

	private function blockOfPurpose(DataElementPurpose $purpose): ?string
	{
		return match ($purpose)
		{
			DataElementPurpose::Input => 'PARAMETERS',
			DataElementPurpose::Internal => 'VARIABLES',
			DataElementPurpose::Constant => 'CONSTANTS',
			DataElementPurpose::Shared => null,
		};
	}

	private function sourceTypeOfBlock(string $block): string
	{
		return (string)array_search($block, self::BLOCK_OF_SOURCE_TYPE, true);
	}

	private function isSameKey(string $key, string $otherKey): bool
	{
		return mb_strtolower($key) === mb_strtolower($otherKey);
	}
}
