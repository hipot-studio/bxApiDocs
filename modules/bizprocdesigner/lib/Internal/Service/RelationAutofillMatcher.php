<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service;

use Bitrix\Bizproc\Public\Activity\Interface\RelationFieldResolver;
use Bitrix\Bizproc\Public\Activity\Registry\RelationFieldResolverRegistry;
use Bitrix\BizprocDesigner\Internal\Dto\RelationAutofillResult;

/**
 * Design-time matcher that builds the autofill package for a "Create" sub-action of a target node:
 * a map of target form field code -> bizproc expression fed by a relation ancestor.
 *
 * It never transforms values and never computes anything: it mirrors direct relation rules provided by
 * the target module's {@see RelationFieldResolver} and package fields already published by the ancestor.
 * A field is filled only when exactly one ancestor unambiguously feeds it; a missing resolver or a target
 * the resolver does not support disables relation-field matching but does not affect direct package fields.
 * No match or an ambiguous match leaves the field empty (fail-open) so the frontend fills it manually.
 *
 * The service is created per request; the relation map is cached in-instance per (source, target)
 * document-type pair so repeated ancestors of the same type never hit the resolver twice.
 */
final class RelationAutofillMatcher
{
	private const ORIGIN_PACKAGE = 'package';
	private const ORIGIN_RESOLVER = 'resolver';

	/** @var array<string, array<string, array{sourcePropertyId: string, required: bool}>> */
	private array $relationCache = [];

	public function __construct(
		private readonly RelationFieldResolverRegistry $registry,
	)
	{
	}

	/**
	 * @param list<array{
	 *   blockId: string,
	 *   documentType: array,
	 *   outputs: list<string>,
	 *   outputSet?: array<string, true>,
	 *   documentOutput: string,
	 *   package?: list<array{id: string, fieldId: string, required?: bool}>
	 * }> $sourceCandidates
	 *   Relation ancestors. `outputs` are the normalized output codes the ancestor exposes for reference;
	 *   `documentOutput` is the code of the ancestor's document output through which the linked entity id
	 *   is reached (accessor `documentOutput.propertyId`). `package` entries feed target fields directly
	 *   from ancestor outputs.
	 * @param array $targetDocumentType Standard Bitrix triplet [moduleId, entity, documentType] of the
	 *   target ("Create") node.
	 *
	 * @return array<string, string> Autofill map: field code => a bizproc expression.
	 *   Empty when there is no unambiguous match.
	 */
	public function buildAutofillMap(array $sourceCandidates, array $targetDocumentType): array
	{
		return $this->buildAutofillResult($sourceCandidates, $targetDocumentType)->autofillMap;
	}

	public function buildAutofillResult(array $sourceCandidates, array $targetDocumentType): RelationAutofillResult
	{
		$resolver = $this->registry->resolve($targetDocumentType);
		$relationFieldsSupported = $resolver !== null && $resolver->supports($targetDocumentType);

		/** @var array<string, list<array{expression: string, blockId: string, required: bool, origin: string}>> $targetFields */
		$targetFields = [];
		foreach ($sourceCandidates as $candidate)
		{
			$blockId = (string)($candidate['blockId'] ?? '');
			$sourceDocumentType = $candidate['documentType'] ?? null;
			$outputs = $candidate['outputs'] ?? [];
			$documentOutput = (string)($candidate['documentOutput'] ?? '');
			if ($blockId === '' || !is_array($sourceDocumentType) || !is_array($outputs))
			{
				continue;
			}

			// Derived from `outputs` and so shaped only after the guard above accepted them: this service
			// answers a malformed candidate with an empty map, never with a type error.
			$outputSet = is_array($candidate['outputSet'] ?? null)
				? $candidate['outputSet']
				: array_fill_keys($outputs, true);

			if (
				$relationFieldsSupported
				&& $documentOutput !== ''
				&& isset($outputSet[$documentOutput])
			)
			{
				/** @var RelationFieldResolver $resolver */
				$relationFields = $this->resolveRelationFields($resolver, $sourceDocumentType, $targetDocumentType);
				foreach ($relationFields as $fieldCode => $descriptor)
				{
					// sourcePropertyId is a field INSIDE the source document, reached via the
					// ancestor's document output accessor, so it is not looked up among `outputs`.
					$sourcePropertyId = (string)($descriptor['sourcePropertyId'] ?? '');
					if ($sourcePropertyId === '')
					{
						continue;
					}

					$expression = '{='
						. $blockId
						. ':'
						. $documentOutput
						. '.'
						. $sourcePropertyId
						. '}';
					$targetFields[(string)$fieldCode][] = [
						'expression' => $expression,
						'blockId' => $blockId,
						'required' => ($descriptor['required'] ?? false) === true,
						'origin' => self::ORIGIN_RESOLVER,
					];
				}
			}

			$package = is_array($candidate['package'] ?? null) ? $candidate['package'] : [];
			foreach ($package as $entry)
			{
				if (!is_array($entry))
				{
					continue;
				}

				$sourcePropertyId = $entry['id'] ?? null;
				$fieldId = $entry['fieldId'] ?? null;
				if (
					!is_string($sourcePropertyId)
					|| $sourcePropertyId === ''
					|| !is_string($fieldId)
					|| $fieldId === ''
				)
				{
					continue;
				}

				$targetFields[$fieldId][] = [
					'expression' => '{=' . $blockId . ':' . $sourcePropertyId . '}',
					'blockId' => $blockId,
					'required' => ($entry['required'] ?? false) === true,
					'origin' => self::ORIGIN_PACKAGE,
				];
			}
		}

		$autofillMap = [];
		$autofillRequiredFields = [];
		$autofillConflicts = [];
		foreach ($targetFields as $fieldCode => $matches)
		{
			if ($this->isRequiredByPackage($matches))
			{
				$autofillRequiredFields[] = (string)$fieldCode;
			}

			$distinct = array_values(array_unique(array_column($matches, 'expression')));
			if (count($distinct) === 1)
			{
				$autofillMap[$fieldCode] = $distinct[0];
			}
			else
			{
				$sourceBlockIds = array_values(array_unique(array_column($matches, 'blockId')));
				sort($sourceBlockIds, SORT_STRING);
				$autofillConflicts[] = [
					'fieldId' => (string)$fieldCode,
					'code' => 'ambiguous_source',
					'sourceBlockIds' => $sourceBlockIds,
				];
			}
		}

		return new RelationAutofillResult($autofillMap, $autofillRequiredFields, $autofillConflicts);
	}

	/**
	 * Only the relation package answers for the required flag the frontend acts on. A resolver reads that
	 * flag off the metadata of the target document, where the field has always been required and is filled
	 * by hand as a matter of course: raising a conflict of the relation over it would block the save of a
	 * node that used to save fine.
	 *
	 * @param list<array{expression: string, blockId: string, required: bool, origin: string}> $matches
	 */
	private function isRequiredByPackage(array $matches): bool
	{
		foreach ($matches as $match)
		{
			if ($match['required'] === true && $match['origin'] === self::ORIGIN_PACKAGE)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<string, array{sourcePropertyId: string, required: bool}>
	 */
	private function resolveRelationFields(
		RelationFieldResolver $resolver,
		array $sourceDocumentType,
		array $targetDocumentType,
	): array
	{
		$key = $this->cacheKey($sourceDocumentType, $targetDocumentType);
		if (!array_key_exists($key, $this->relationCache))
		{
			$this->relationCache[$key] = $resolver->resolveRelationFields($sourceDocumentType, $targetDocumentType);
		}

		return $this->relationCache[$key];
	}

	private function cacheKey(array $sourceDocumentType, array $targetDocumentType): string
	{
		return implode(':', $sourceDocumentType) . '=>' . implode(':', $targetDocumentType);
	}
}
