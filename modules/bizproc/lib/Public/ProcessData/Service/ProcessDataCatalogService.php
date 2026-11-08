<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\ProcessData\Service;

use Bitrix\Bizproc\Public\ProcessData\Dto\ProcessDataCatalog;
use Bitrix\Bizproc\Public\ProcessData\Dto\ProcessDataElement;
use Bitrix\Bizproc\Public\ProcessData\Enum\DataElementPurpose;
use Bitrix\Bizproc\Public\Service\Template\ActivityUsageAnalyzer;
use Bitrix\Bizproc\Public\Service\Template\ExpressionReferenceScanner;
use Bitrix\Bizproc\Workflow\Template\SourceType;
use Bitrix\Bizproc\Workflow\Type\GlobalConst;
use Bitrix\Bizproc\Workflow\Type\GlobalVar;
use Bitrix\Main\Localization\Loc;

final class ProcessDataCatalogService
{
	private const ELEMENT_SOURCE_TYPES = [SourceType::Parameter, SourceType::Variable, SourceType::Constant];

	public function readByBlocks(array $parameters, array $variables, array $constants): ProcessDataCatalog
	{
		$inputHalfKeys = $this->findInputHalfKeys($parameters, $variables);
		$elements = [];

		foreach ($parameters as $key => $property)
		{
			$inputHalfKey = $inputHalfKeys[$this->normalizeKey((string)$key)] ?? null;

			// Hidden is the very key taken as the input half, not every key written the same way: a second
			// parameter of the same key stays an element of its own and reaches the validator as a duplicate.
			if ($inputHalfKey === null || (string)$inputHalfKey !== (string)$key)
			{
				$elements[] = $this->createElement((string)$key, SourceType::Parameter, $property);
			}
		}

		foreach ($variables as $key => $property)
		{
			$inputHalfKey = $inputHalfKeys[$this->normalizeKey((string)$key)] ?? null;

			$elements[] = $inputHalfKey === null
				? $this->createElement((string)$key, SourceType::Variable, $property)
				: new ProcessDataElement(
					key: (string)$key,
					sourceType: SourceType::Variable,
					purpose: DataElementPurpose::Internal,
					property: $property,
					acceptsStartValue: true,
					inputProperty: $parameters[$inputHalfKey],
				)
			;
		}

		foreach ($constants as $key => $property)
		{
			$elements[] = $this->createElement((string)$key, SourceType::Constant, $property);
		}

		return new ProcessDataCatalog($elements);
	}

	/**
	 * @return ProcessDataElement[]
	 */
	public function readShared(array $documentType): array
	{
		return array_merge(
			$this->createSharedElements(GlobalVar::getAll($documentType), SourceType::GlobalVariable),
			$this->createSharedElements(GlobalConst::getAll($documentType), SourceType::GlobalConstant),
		);
	}

	/**
	 * Places the element is answered from: the properties of the activities and the default values of the
	 * other elements. A reference standing in a default value holds the element exactly as a reference of an
	 * activity does — the publication is refused by an unresolved one either way.
	 *
	 * @return array<int, array{activityName: string, title: string}>
	 */
	public function findUsages(
		array $template,
		string $sourceType,
		string $key,
		array $parameters = [],
		array $variables = [],
		array $constants = [],
	): array
	{
		$sourceTypes = $this->sourceTypesOfElement($sourceType);
		$analyzer = new ActivityUsageAnalyzer($template);

		return array_merge(
			$analyzer->analyzeDataElementUsages($sourceTypes, $key),
			$this->findDefaultValueUsages(
				[
					SourceType::Parameter => $parameters,
					SourceType::Variable => $variables,
					SourceType::Constant => $constants,
				],
				$sourceTypes,
				$key,
			),
		);
	}

	/**
	 * @param array<string, array> $blocks
	 * @param string[] $sourceTypes
	 *
	 * @return array<int, array{activityName: string, title: string}>
	 */
	private function findDefaultValueUsages(array $blocks, array $sourceTypes, string $key): array
	{
		$usages = [];

		foreach ($blocks as $blockSourceType => $block)
		{
			foreach ($block as $ownerKey => $property)
			{
				$isElementItself =
					in_array($blockSourceType, $sourceTypes, true)
					&& $this->normalizeKey((string)$ownerKey) === $this->normalizeKey($key)
				;

				if ($isElementItself || !is_array($property))
				{
					continue;
				}

				if (ExpressionReferenceScanner::hasReference($property['Default'] ?? null, $sourceTypes, $key))
				{
					$usages[] = [
						'activityName' => 'Default:' . $blockSourceType . ':' . $ownerKey,
						'title' => str_replace(
							'#NAME#',
							(string)($property['Name'] ?? $ownerKey),
							Loc::getMessage('BIZPROC_PROCESS_DATA_CATALOG_USAGE_DEFAULT_VALUE') ?? '',
						),
					];
				}
			}
		}

		return $usages;
	}

	/**
	 * References to data elements standing in the properties of the activities of the template and in the
	 * default values of its blocks. A reference naming another source - the document, the system, a shared
	 * value, the returned field of a node - is answered by no catalog and is left out. The element an activity
	 * writes into is one of them: a node writing into a deleted element is as unresolved as one reading it.
	 *
	 * @return array<int, array{sourceType: string, key: string, activityName: ?string}> activityName is null
	 *     for a reference standing in a default value.
	 */
	public function collectElementReferences(
		array $template,
		array $parameters = [],
		array $variables = [],
		array $constants = [],
	): array
	{
		$references = [];

		foreach (ActivityUsageAnalyzer::collectTemplateActivities($template) as $activity)
		{
			$activityName = (string)($activity['Name'] ?? '');

			foreach ($this->findElementReferences($activity['Properties'] ?? null) as $reference)
			{
				$references[] = $reference + ['activityName' => $activityName];
			}

			// A writer names its element by the key of a row, and the references above are read from the
			// values alone (Q-6).
			foreach (ActivityWriteTargets::of($activity) as $target)
			{
				if (in_array($target['sourceType'], self::ELEMENT_SOURCE_TYPES, true))
				{
					$references[] = $target + ['activityName' => $activityName];
				}
			}
		}

		foreach ([$parameters, $variables, $constants] as $block)
		{
			foreach ($block as $property)
			{
				if (!is_array($property))
				{
					continue;
				}

				foreach ($this->findElementReferences($property['Default'] ?? null) as $reference)
				{
					$references[] = $reference + ['activityName' => null];
				}
			}
		}

		return $references;
	}

	/**
	 * An inline reference stands both on its own and inside a calculated expression, so one parse covers
	 * `{=Source:Key}` and `{{=...}}`.
	 *
	 * @return array<int, array{sourceType: string, key: string}>
	 */
	private function findElementReferences(mixed $value): array
	{
		$references = [];

		if (is_array($value))
		{
			foreach ($value as $item)
			{
				array_push($references, ...$this->findElementReferences($item));
			}

			return $references;
		}

		foreach (\CBPActivity::findExpressions($value) as $expression)
		{
			$source = SourceType::getObjectSourceType($expression['object'], $expression['field']);

			if ($source !== null && in_array($source[0], self::ELEMENT_SOURCE_TYPES, true))
			{
				$references[] = ['sourceType' => $source[0], 'key' => $source[1]];
			}
		}

		return $references;
	}

	/**
	 * A pair keeps one key in both blocks, and its element answers as a variable: a reference to the input
	 * half is a reference to the same element.
	 *
	 * @return string[]
	 */
	private function sourceTypesOfElement(string $sourceType): array
	{
		return $sourceType === SourceType::Variable
			? [SourceType::Variable, SourceType::Parameter]
			: [$sourceType]
		;
	}

	/**
	 * Pairs as the start of a process needs them: the working half and the input half it takes its value
	 * from. The keys are answered as the blocks write them, the pairing itself is the one the catalog shows.
	 *
	 * @return array Key of the variable => key of the parameter it takes its value from.
	 */
	public function findStartValuePairs(array $parameters, array $variables): array
	{
		$inputHalfKeys = $this->findInputHalfKeys($parameters, $variables);

		$pairs = [];
		foreach (array_keys($variables) as $key)
		{
			$inputHalfKey = $inputHalfKeys[$this->normalizeKey((string)$key)] ?? null;

			if ($inputHalfKey !== null)
			{
				$pairs[$key] = $inputHalfKey;
			}
		}

		return $pairs;
	}

	/**
	 * Keys of the working halves of pairs: the same key standing both in `PARAMETERS` and in `VARIABLES`.
	 * The condition repeats the one the start uses (ALG-03), so the catalog never shows a pair the runtime
	 * would not initialize.
	 *
	 * @return array<string, string> Normalized key of the working half => key of the input half.
	 */
	private function findInputHalfKeys(array $parameters, array $variables): array
	{
		$parameterKeys = [];
		foreach (array_keys($parameters) as $key)
		{
			$parameterKeys[$this->normalizeKey((string)$key)] = $key;
		}

		$inputHalfKeys = [];
		foreach (array_keys($variables) as $key)
		{
			$normalizedKey = $this->normalizeKey((string)$key);

			if (isset($parameterKeys[$normalizedKey]))
			{
				$inputHalfKeys[$normalizedKey] = $parameterKeys[$normalizedKey];
			}
		}

		return $inputHalfKeys;
	}

	private function createElement(string $key, string $sourceType, array $property): ProcessDataElement
	{
		return new ProcessDataElement(
			key: $key,
			sourceType: $sourceType,
			purpose: DataElementPurpose::ofSourceType($sourceType),
			property: $property,
		);
	}

	/**
	 * @return ProcessDataElement[]
	 */
	private function createSharedElements(array $rows, string $sourceType): array
	{
		$elements = [];

		foreach ($rows as $id => $property)
		{
			$visibility = $property['Visibility'] ?? null;

			$elements[] = new ProcessDataElement(
				key: (string)$id,
				sourceType: $sourceType,
				purpose: DataElementPurpose::Shared,
				property: $property,
				visibility: $visibility === null ? null : (string)$visibility,
			);
		}

		return $elements;
	}

	private function normalizeKey(string $key): string
	{
		return mb_strtolower($key);
	}
}
