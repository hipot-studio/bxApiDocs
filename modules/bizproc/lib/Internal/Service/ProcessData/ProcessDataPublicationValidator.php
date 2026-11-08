<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\ProcessData;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Public\ProcessData\Service\ProcessDataCatalogService;
use Bitrix\Bizproc\Public\ProcessData\Service\ProcessDataMechanicsResolver;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\SourceType;

final class ProcessDataPublicationValidator
{
	/** The scheme and the data blocks: everything the check of the data elements is answered from. */
	private const VALIDATED_FIELDS = ['TEMPLATE', 'PARAMETERS', 'VARIABLES', 'CONSTANTS'];

	/** The blocks the data elements themselves stand in. */
	private const BLOCK_FIELDS = ['PARAMETERS', 'VARIABLES', 'CONSTANTS'];

	/**
	 * Keys of the data elements and references to them, refused before the template is written. The check
	 * stands only while the feature is on and only for a template of the new mechanics: a template of today
	 * keeps publishing with the key conflicts and the dangling references it already carries.
	 *
	 * @param int $id Id of the template on an update, 0 while it is being added.
	 * @return list<array> Errors in the shape the validation of the tree collects.
	 */
	public function collectErrors(array $savedFields, int $id): array
	{
		if (!ProcessDataFeature::isEnabled())
		{
			return [];
		}

		if (!(new ProcessDataMechanicsResolver())->isNewMechanicsForFields($savedFields, $id))
		{
			return [];
		}

		$fields = $this->resolveFields($savedFields, $id);
		$catalogService = new ProcessDataCatalogService();
		$catalog = $catalogService->readByBlocks(
			$fields['PARAMETERS'],
			$fields['VARIABLES'],
			$fields['CONSTANTS'],
		);

		$errors = [];
		foreach ((new DataElementKeyValidator())->validateCatalog($catalog)->getErrors() as $keyError)
		{
			$errors[] = [
				'code' => $keyError->getCode(),
				'message' => $keyError->getMessage(),
				'customData' => $keyError->getCustomData(),
			];
		}

		foreach ($this->collectElementTypeErrors($fields, $id) as $typeError)
		{
			$errors[] = $typeError;
		}

		$references = $catalogService->collectElementReferences(
			$fields['TEMPLATE'],
			$fields['PARAMETERS'],
			$fields['VARIABLES'],
			$fields['CONSTANTS'],
		);

		$reported = [];
		foreach ($references as $reference)
		{
			$reportKey = mb_strtolower($reference['key']) . '@' . $reference['activityName'];
			if (isset($reported[$reportKey]))
			{
				continue;
			}

			if ($this->isResolvedReference($fields, $reference))
			{
				continue;
			}

			$reported[$reportKey] = true;

			$error = ErrorMessage::PROCESS_DATA_UNRESOLVED_REFERENCE->getCodedError(['#KEY#' => $reference['key']]);
			$unresolvedReference = [
				'code' => $error->getCode(),
				'message' => $error->getMessage(),
			];

			if ($reference['activityName'] !== null)
			{
				$unresolvedReference['activityName'] = $reference['activityName'];
			}

			$errors[] = $unresolvedReference;
		}

		return $errors;
	}

	/**
	 * The runtime reads an element out of the block its source names and by the very key the reference
	 * carries, so a reference differing in case is answered by nobody and stands as unresolved here. The
	 * block is asked and not the catalog: the input half of a pair keeps its own key in the parameters while
	 * the catalog shows the pair under the key of the variable. A built-in parameter is answered by the
	 * activity itself and stands in no block at all.
	 *
	 * @param array<string, array> $fields
	 * @param array{key: string, sourceType: string} $reference
	 */
	private function isResolvedReference(array $fields, array $reference): bool
	{
		if (
			$reference['sourceType'] === SourceType::Parameter
			&& in_array(
				mb_strtolower($reference['key']),
				array_map(mb_strtolower(...), SourceType::BUILT_IN_PARAMETER_KEYS),
				true,
			)
		)
		{
			return true;
		}

		$block = DataElementKeyValidator::BLOCK_OF_SOURCE_TYPE[$reference['sourceType']];

		return array_key_exists($reference['key'], $fields[$block]);
	}

	/**
	 * Types of the data elements, held to the set the settings offer. The type an element already stands with
	 * is left alone: an element written by the legacy designer publishes with the type it carries, and refused
	 * is only a type entering the template now. The element is recognized by its key and not by its address in
	 * a block, so a move between the blocks keeps its type; a renamed one is answered by the element of the
	 * same type whose key the save no longer carries - the one that left is the one that came back renamed.
	 * Each departed element answers for one arriving, so a second element of that type is still refused. The
	 * stored row is read only when there is something to compare with it - a save carrying base types alone
	 * asks the database nothing.
	 *
	 * @param array<string, array> $fields
	 * @return list<array>
	 */
	private function collectElementTypeErrors(array $fields, int $id): array
	{
		$validator = new DataElementTypeValidator();
		$suspects = [];

		foreach (self::BLOCK_FIELDS as $block)
		{
			foreach ($fields[$block] as $key => $property)
			{
				$type = is_array($property) ? (string)($property['Type'] ?? '') : '';

				if ($type !== '' && !$validator->validateType($type)->isSuccess())
				{
					$suspects[$block][(string)$key] = $type;
				}
			}
		}

		if (!$suspects)
		{
			return [];
		}

		$savedKeys = [];
		foreach (self::BLOCK_FIELDS as $block)
		{
			foreach (array_keys($fields[$block]) as $key)
			{
				$savedKeys[mb_strtolower((string)$key)] = true;
			}
		}

		$storedTypeOfKey = [];
		$departedTypes = [];

		foreach ($this->readStoredElements($id) as [$storedKey, $storedType])
		{
			$storedKey = mb_strtolower($storedKey);
			$storedTypeOfKey[$storedKey][$storedType] = true;

			if (!isset($savedKeys[$storedKey]))
			{
				$departedTypes[$storedType] = ($departedTypes[$storedType] ?? 0) + 1;
			}
		}

		$errors = [];
		$reported = [];

		foreach ($suspects as $block => $types)
		{
			foreach ($types as $key => $type)
			{
				if (isset($storedTypeOfKey[mb_strtolower((string)$key)][$type]))
				{
					continue;
				}

				if (($departedTypes[$type] ?? 0) > 0)
				{
					$departedTypes[$type] -= 1;

					continue;
				}

				$reportKey = mb_strtolower($key);
				if (isset($reported[$reportKey]))
				{
					continue;
				}

				$reported[$reportKey] = true;

				$error = ErrorMessage::PROCESS_DATA_TYPE_UNSUPPORTED->getCodedError(
					['#KEY#' => $key],
					['key' => $key, 'blocks' => [$block]],
				);
				$errors[] = [
					'code' => $error->getCode(),
					'message' => $error->getMessage(),
					'customData' => $error->getCustomData(),
				];
			}
		}

		return $errors;
	}

	/**
	 * Elements the stored template carries in its blocks, as pairs of the key and the type.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private function readStoredElements(int $id): array
	{
		if ($id <= 0)
		{
			return [];
		}

		$storedBlocks = WorkflowTemplateTable::getRow([
			'select' => self::BLOCK_FIELDS,
			'filter' => ['=ID' => $id],
		]) ?? [];

		$elements = [];

		foreach (self::BLOCK_FIELDS as $block)
		{
			foreach ((array)($storedBlocks[$block] ?? []) as $key => $property)
			{
				$type = is_array($property) ? ($property['Type'] ?? null) : null;

				if (is_scalar($type))
				{
					$elements[] = [(string)$key, (string)$type];
				}
			}
		}

		return $elements;
	}

	/**
	 * Fields of the save; a field the save does not carry stands as it is stored. So a save of the scheme
	 * alone does not take the references of the template for unresolved ones, and a save of one block alone
	 * is asked about the references the stored scheme holds.
	 *
	 * @return array<string, array>
	 */
	private function resolveFields(array $savedFields, int $id): array
	{
		$resolved = [];
		$missing = [];

		foreach (self::VALIDATED_FIELDS as $field)
		{
			$carried = $savedFields[$field] ?? null;
			$resolved[$field] = is_array($carried) ? $carried : [];

			if (!is_array($carried))
			{
				$missing[] = $field;
			}
		}

		if (!$missing || $id <= 0)
		{
			return $resolved;
		}

		$storedFields = WorkflowTemplateTable::getRow([
			'select' => $missing,
			'filter' => ['=ID' => $id],
		]) ?? [];

		foreach ($missing as $field)
		{
			if (is_array($storedFields[$field] ?? null))
			{
				$resolved[$field] = $storedFields[$field];
			}
		}

		return $resolved;
	}
}
