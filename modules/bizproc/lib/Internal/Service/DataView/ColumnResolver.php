<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Exception\DataView\InvalidDataViewDefinitionException;
use Bitrix\Bizproc\Internal\Model\StorageFieldTable;
use Bitrix\Bizproc\Internal\Repository\Mapper\StorageItemMapper;
use Bitrix\Bizproc\Internal\Repository\StorageFieldRepository\StorageFieldRepositoryInterface;
use Bitrix\Bizproc\Internal\Service\DataView\Provider\VariablesDataSourceProvider;
use Bitrix\Bizproc\Internal\Service\Storage\StorageLimitsService;
use Bitrix\Bizproc\Public\DataView\Dto\AggregateFunction;
use Bitrix\Bizproc\Public\DataView\Dto\SourceField;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;
use Bitrix\Bizproc\Public\DataView\Dto\SourceSchema;
use Bitrix\Bizproc\Public\DataView\Exception\KeyNotIndexedException;
use Bitrix\Bizproc\Public\DataView\Exception\KeyNotJoinableException;
use Bitrix\Bizproc\Public\DataView\Exception\KeyTypeMismatchException;
use Bitrix\Bizproc\Public\DataView\Exception\SourceUnavailableException;
use Bitrix\Bizproc\Public\DataView\Interface\DataSourceProvider;
use Bitrix\Bizproc\Public\DataView\Interface\StampConstantProvider;
use Bitrix\Bizproc\Public\DataView\Registry\DataSourceProviderRegistry;

final class ColumnResolver
{
	/**
	 * The only format the engine knows how to materialize. A definition written by a newer client
	 * must be rejected rather than silently computed with v1 semantics; definitions stored before
	 * the field existed carry no version and are v1 by definition.
	 */
	public const SUPPORTED_VERSION = 1;

	private const OPERATION_JOIN = 'join';
	private const OPERATION_AGGREGATE = 'aggregate';
	private const OPERATION_PROJECT = 'project';

	/** Source entities whose data belongs to a single workflow template. */
	private const TEMPLATE_SCOPED_ENTITIES = [
		VariablesDataSourceProvider::ENTITY_TEMPLATE_CONSTANT,
	];

	public function __construct(
		private readonly DataSourceProviderRegistry $providerRegistry,
		private readonly StorageLimitsService $limitsService,
		private readonly StorageFieldRepositoryInterface $storageFieldRepository,
		private readonly PeriodResolver $periodResolver,
	) {
	}

	/**
	 * Resolution answers what the columns are, not whose they are: the owner of a definition is not
	 * part of the definition, so the ownership rule stays in {@see self::assertTemplateSourcesOwned()}
	 * and is applied by the boundary that knows the owner.
	 *
	 * @param array|null $previousDefinition definition the result storage was built from, when the view
	 *   is being updated; it tells a formula that has just appeared or gone from a column that never
	 *   carried one ({@see self::assertColumnTypesUnchanged()}).
	 */
	public function resolve(
		array $definition,
		?int $targetStorageTypeId = null,
		?array $previousDefinition = null,
	): array
	{
		$this->assertSupportedVersion($definition);
		$operation = $this->parseOperation($definition);
		$this->assertPeriod($definition);

		$sourcesByAlias = $this->parseSources($definition, $operation);

		$schemaByAlias = $this->resolveSchemas($sourcesByAlias);
		$existingTypes = $this->getExistingFieldTypes($targetStorageTypeId);
		$previousFormulas = ColumnFormula::mapByColumnCode((array)$previousDefinition);

		if ($operation === self::OPERATION_AGGREGATE)
		{
			return $this->finalizeColumns(
				$this->buildAggregateColumns($definition, $sourcesByAlias, $schemaByAlias),
				$definition,
				$existingTypes,
				$previousFormulas,
			);
		}

		if ($operation === self::OPERATION_PROJECT)
		{
			return $this->finalizeColumns(
				$this->buildProjectColumns($definition, $sourcesByAlias, $schemaByAlias),
				$definition,
				$existingTypes,
				$previousFormulas,
			);
		}

		$plan = $this->parseJoinKeys($definition, $sourcesByAlias);
		$this->assertPeriodResolvable($definition, $plan);
		$this->assertKeyTypesCompatible($plan, $schemaByAlias);

		return $this->finalizeColumns(
			$this->buildColumns($definition, $sourcesByAlias, $plan, $schemaByAlias),
			$definition,
			$existingTypes,
			$previousFormulas,
		);
	}

	/**
	 * @param array<string, string> $existingTypes types of the result fields the storage already has
	 * @param array<string, string> $previousFormulas formulas of the stored definition, keyed by column code
	 * @throws InvalidDataViewDefinitionException
	 */
	private function finalizeColumns(
		array $columns,
		array $definition,
		array $existingTypes,
		array $previousFormulas,
	): array
	{
		$columns = $this->applyFormulas($columns, $definition);
		$this->assertColumnTypesUnchanged($columns, $existingTypes, $previousFormulas);
		$this->assertWithinFieldLimit($columns, $existingTypes);

		return $columns;
	}

	/**
	 * A result field keeps the type it was created with, so a column that changes its type costs the
	 * field its data: it is dropped and created anew ({@see \Bitrix\Bizproc\Public\Command\DataView\SaveDataViewCommandHandler}).
	 * A formula is the one change worth that price and the author asks for it knowingly: it turns the
	 * column into the string its result is, and removing it gives the source type back. Any other type
	 * change — a column re-pointed at a field of another type — is rejected instead of silently emptying
	 * the column for every consumer of the view.
	 *
	 * @param array<string, string> $existingTypes
	 * @param array<string, string> $previousFormulas
	 * @throws InvalidDataViewDefinitionException
	 */
	private function assertColumnTypesUnchanged(array $columns, array $existingTypes, array $previousFormulas): void
	{
		foreach ($columns as $column)
		{
			$code = (string)$column['code'];
			$existingType = $existingTypes[$code] ?? null;
			$newType = (string)$column['type'];

			if (
				$existingType === null
				|| $existingType === $newType
				|| $this->isTypeChangeExplainedByFormula($column, $previousFormulas)
			)
			{
				continue;
			}

			throw InvalidDataViewDefinitionException::forField(
				$code,
				sprintf('DataView column type cannot be changed from "%s" to "%s".', $existingType, $newType),
				InvalidDataViewDefinitionException::VIOLATION_COLUMN_TYPE_CHANGED,
			);
		}
	}

	/**
	 * A column carrying a formula is a string one ({@see self::applyFormulas()}), so the type follows
	 * the formula only where the formula itself has changed: appeared over a column that had none, or
	 * gone from a column that had one. The stored definition is what tells the two apart — the string
	 * type of the field alone does not, because a column of a string source field is string as well.
	 *
	 * @param array<string, mixed> $column
	 * @param array<string, string> $previousFormulas
	 */
	private function isTypeChangeExplainedByFormula(array $column, array $previousFormulas): bool
	{
		return isset($column['formula']) !== isset($previousFormulas[(string)$column['code']]);
	}

	/**
	 * A formula replaces the value the column took from its source, so whatever the source field was,
	 * the column keeps the computed result and becomes a string one. The type the value came from stays
	 * with the column: an output modifier is applied over it by {@see ValuePresenter}. Validation runs
	 * over the resolved list: a reference is a column of the same table, and only its final composition
	 * tells whether the referenced column is there at all and whether it is computed itself.
	 *
	 * The cardinality follows the value the column now holds, not the field it used to take it from: a
	 * computed expression and a reference presented through an output modifier are both a single string
	 * ({@see \CBPHelper::stringify()}, {@see \Bitrix\Bizproc\Internal\Service\DocumentField\FieldValueFormatter::format()}),
	 * while a bare reference keeps the value of the referenced column as it is, multiple included.
	 *
	 * @throws InvalidDataViewDefinitionException
	 */
	private function applyFormulas(array $columns, array $definition): array
	{
		$formulas = ColumnFormula::mapByColumnCode($definition);
		if ($formulas === [])
		{
			return $columns;
		}

		$knownCodes = array_fill_keys(array_column($columns, 'code'), true);
		$multipleByCode = array_column($columns, 'multiple', 'code');
		foreach ($columns as $index => $column)
		{
			$code = (string)$column['code'];
			$formula = $formulas[$code] ?? null;
			if ($formula === null || ($column['kind'] ?? null) === 'constant')
			{
				continue;
			}

			ColumnFormula::assertValid($formula, $code, $knownCodes, $formulas);

			$reference = ColumnFormula::pureReference($formula);

			$columns[$index]['formula'] = $formula;
			$columns[$index]['sourceType'] = (string)$column['type'];
			$columns[$index]['type'] = FieldType::STRING;
			$columns[$index]['multiple'] = $reference !== null
				&& $reference['format'] === ''
				&& (bool)($multipleByCode[$reference['code']] ?? false)
			;
		}

		return $columns;
	}

	public static function isSupportedVersion(array $definition): bool
	{
		$version = $definition['version'] ?? self::SUPPORTED_VERSION;

		return is_numeric($version) && (int)$version === self::SUPPORTED_VERSION;
	}

	/**
	 * A template constant or variable belongs to the template that declares it, so a definition may
	 * reference it only while it is written for that very template. The owner is known outside the
	 * definition (on save it is the owner of the view, on preview the template being edited), hence
	 * the rule is exposed on its own and every entry point that accepts a definition from outside
	 * ({@see \Bitrix\Bizproc\Public\Command\DataView\SaveDataViewCommandHandler},
	 * {@see \Bitrix\Bizproc\Public\DataView\Service\PreviewService}) calls it with the owner it holds.
	 * Validation through {@see DataViewValidator} carries no owner and therefore must not apply it:
	 * a null owner here means "no template source is allowed", not "any owner will do".
	 * A definition without template-scoped sources is owner-agnostic and stays valid with any owner,
	 * including none.
	 *
	 * @throws InvalidDataViewDefinitionException
	 */
	public static function assertTemplateSourcesOwned(array $definition, ?int $ownerTemplateId): void
	{
		foreach (self::collectSourceRefs($definition) as $ref)
		{
			if (!self::isTemplateScopedRef($ref))
			{
				continue;
			}

			// the client may send reference params as strings, so the template id is compared as an int
			$sourceTemplateId = (int)$ref->getParam('templateId', 0);
			if ($ownerTemplateId === null || $ownerTemplateId <= 0 || $sourceTemplateId !== $ownerTemplateId)
			{
				throw new InvalidDataViewDefinitionException(
					sprintf(
						'DataView source "%s" is scoped to workflow template %d and is not available to the owner template %s.',
						$ref->entity,
						$sourceTemplateId,
						$ownerTemplateId === null ? 'null' : (string)$ownerTemplateId,
					),
					violation: InvalidDataViewDefinitionException::VIOLATION_TEMPLATE_SOURCE_FOREIGN,
				);
			}
		}
	}

	/**
	 * Whether the result of such a definition carries data of a single workflow template, and so
	 * stays readable only within that template.
	 */
	public static function hasTemplateScopedSources(array $definition): bool
	{
		foreach (self::collectSourceRefs($definition) as $ref)
		{
			if (self::isTemplateScopedRef($ref))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Sources the rows of the result are read from; stamp constants are not among them.
	 *
	 * @return SourceRef[]
	 */
	public static function collectRowSourceRefs(array $definition): array
	{
		return array_map(
			static fn ($source): SourceRef => SourceRef::fromArray((array)$source),
			(array)($definition['sources'] ?? []),
		);
	}

	/**
	 * Sources and stamp constants alike: a stamp reads a value just as a source does.
	 *
	 * @return SourceRef[]
	 */
	private static function collectSourceRefs(array $definition): array
	{
		$references = self::collectRowSourceRefs($definition);
		foreach ((array)($definition['columns'] ?? []) as $column)
		{
			$column = (array)$column;
			if (($column['kind'] ?? null) === 'constant')
			{
				$references[] = SourceRef::fromArray((array)($column['constant'] ?? []));
			}
		}

		return $references;
	}

	private static function isTemplateScopedRef(SourceRef $ref): bool
	{
		if (DataSourceProviderRegistry::normalizeModuleId($ref->module) !== VariablesDataSourceProvider::MODULE_ID)
		{
			return false;
		}

		return in_array($ref->entity, self::TEMPLATE_SCOPED_ENTITIES, true)
			|| (
				$ref->entity === VariablesDataSourceProvider::ENTITY_GLOBAL_CONSTANT
				&& (int)$ref->getParam('templateId', 0) > 0
			)
		;
	}

	private function assertSupportedVersion(array $definition): void
	{
		if (!self::isSupportedVersion($definition))
		{
			$version = $definition['version'] ?? self::SUPPORTED_VERSION;

			throw new InvalidDataViewDefinitionException(sprintf(
				'Data view definition version "%s" is not supported.',
				is_scalar($version) ? (string)$version : gettype($version),
			));
		}
	}

	private function parseOperation(array $definition): string
	{
		$operation = $definition['operation'] ?? null;
		if (
			$operation !== self::OPERATION_JOIN
			&& $operation !== self::OPERATION_AGGREGATE
			&& $operation !== self::OPERATION_PROJECT
		)
		{
			throw new InvalidDataViewDefinitionException('Data view operation must be "join", "aggregate" or "project".');
		}

		return $operation;
	}

	private function assertPeriod(array $definition): void
	{
		$period = $definition['period'] ?? null;
		if (!is_array($period) || $period === [])
		{
			throw new InvalidDataViewDefinitionException(
				'Data view period is required.',
				violation: InvalidDataViewDefinitionException::VIOLATION_PERIOD,
			);
		}
	}

	private function parseSources(array $definition, string $operation): array
	{
		$sourcesByAlias = [];
		foreach ((array)($definition['sources'] ?? []) as $source)
		{
			$source = (array)$source;
			$alias = (string)($source['alias'] ?? '');
			if ($alias === '')
			{
				continue;
			}

			$sourcesByAlias[$alias] = SourceRef::fromArray($source);
		}

		$expected = in_array($operation, [self::OPERATION_AGGREGATE, self::OPERATION_PROJECT], true) ? 1 : 2;
		if (count($sourcesByAlias) !== $expected)
		{
			throw new InvalidDataViewDefinitionException(
				sprintf(
					'Data view "%s" operation requires exactly %d source(s) with distinct aliases.',
					$operation,
					$expected,
				)
			);
		}

		return $sourcesByAlias;
	}

	private function resolveSchemas(array $sourcesByAlias): array
	{
		$schemaByAlias = [];
		foreach ($sourcesByAlias as $alias => $ref)
		{
			$schemaByAlias[$alias] = $this->resolveProvider($ref)->getSourceSchema($ref);
		}

		return $schemaByAlias;
	}

	private function parseJoinKeys(array $definition, array $sourcesByAlias): array
	{
		$joinKeys = (array)($definition['joinKeys'] ?? []);
		if ($joinKeys === [])
		{
			throw new InvalidDataViewDefinitionException('DataView requires at least one join key.');
		}

		$leftAlias = null;
		$rightAlias = null;
		$keyPairs = [];
		foreach ($joinKeys as $pair)
		{
			[$la, $lf] = $this->splitRef((string)(((array)$pair)['left'] ?? ''));
			[$ra, $rf] = $this->splitRef((string)(((array)$pair)['right'] ?? ''));

			$leftAlias ??= $la;
			$rightAlias ??= $ra;
			if ($la !== $leftAlias || $ra !== $rightAlias)
			{
				throw new InvalidDataViewDefinitionException(
					'DataView join keys must reference a single alias per side.'
				);
			}

			$keyPairs[] = ['left' => $lf, 'right' => $rf];
		}

		if ($leftAlias === $rightAlias || !isset($sourcesByAlias[$leftAlias], $sourcesByAlias[$rightAlias]))
		{
			throw new InvalidDataViewDefinitionException(
				'DataView join keys must reference both distinct sources.'
			);
		}

		return ['leftAlias' => $leftAlias, 'rightAlias' => $rightAlias, 'keyPairs' => $keyPairs];
	}

	private function assertPeriodResolvable(array $definition, array $plan): void
	{

		$leadingAlias = (string)($definition['period']['sourceAlias'] ?? '');
		if ($leadingAlias !== $plan['leftAlias'] && $leadingAlias !== $plan['rightAlias'])
		{
			throw new InvalidDataViewDefinitionException(
				'DataView period.sourceAlias must match a join key alias.',
				violation: InvalidDataViewDefinitionException::VIOLATION_PERIOD,
			);
		}

		$this->periodResolver->resolve((array)$definition['period']);
	}

	private function assertKeyTypesCompatible(array $plan, array $schemaByAlias): void
	{
		$leftSchema = $schemaByAlias[$plan['leftAlias']];
		$rightSchema = $schemaByAlias[$plan['rightAlias']];

		foreach ($plan['keyPairs'] as $pair)
		{
			$leftField = $leftSchema->getField($pair['left']);
			if ($leftField === null)
			{
				throw SourceUnavailableException::sourceFieldRemoved($pair['left']);
			}

			$rightField = $rightSchema->getField($pair['right']);
			if ($rightField === null)
			{
				throw SourceUnavailableException::sourceFieldRemoved($pair['right']);
			}

			$this->assertJoinable($plan['leftAlias'] . '.' . $pair['left'], $leftField);
			$this->assertJoinable($plan['rightAlias'] . '.' . $pair['right'], $rightField);

			if ($this->keyCategory($leftField->type) !== $this->keyCategory($rightField->type))
			{
				throw KeyTypeMismatchException::forKeys(
					$plan['leftAlias'] . '.' . $pair['left'],
					$leftField->type,
					$plan['rightAlias'] . '.' . $pair['right'],
					$rightField->type,
				);
			}
		}
	}

	private function buildColumns(array $definition, array $sourcesByAlias, array $plan, array $schemaByAlias): array
	{
		$provided = (array)($definition['columns'] ?? []);
		if ($provided !== [])
		{
			return $this->enrichProvidedColumns($provided, $plan, $schemaByAlias);
		}

		return $this->deriveColumns($sourcesByAlias, $plan, $schemaByAlias);
	}

	private function enrichProvidedColumns(array $provided, array $plan, array $schemaByAlias): array
	{
		$columns = [];
		$usedCodes = [];
		foreach ($provided as $column)
		{
			$column = (array)$column;
			$code = (string)($column['code'] ?? '');
			if (($column['kind'] ?? null) === 'constant')
			{
				$columns[] = $this->resolveStampColumn($column, $code, $usedCodes);

				continue;
			}

			[$alias, $field] = $this->splitRef((string)($column['source'] ?? ''));

			if ($code === '' || ($alias !== $plan['leftAlias'] && $alias !== $plan['rightAlias']))
			{
				throw new InvalidDataViewDefinitionException(
					'DataView column must reference a known source alias.'
				);
			}

			$this->assertCodeUsableAsStorageField($code);

			if (isset($usedCodes[$code]))
			{
				throw new InvalidDataViewDefinitionException(
					sprintf('DataView column code "%s" is duplicated.', $code),
					violation: InvalidDataViewDefinitionException::VIOLATION_COLUMN_DUPLICATE,
				);
			}

			$sourceField = $schemaByAlias[$alias]->getField($field);
			if ($sourceField === null)
			{
				throw SourceUnavailableException::sourceFieldRemoved($field);
			}

			$usedCodes[$code] = true;
			$resolved = [
				'code' => $code,
				'source' => $alias . '.' . $field,
				'title' => $this->resolveColumnTitle($column, $sourceField->title),
				'type' => $sourceField->type,
				'multiple' => $sourceField->multiple,
			];

			$description = $this->resolveColumnDescription($column);
			if ($description !== null)
			{
				$resolved['description'] = $description;
			}

			$columns[] = $resolved;
		}

		return $columns;
	}

	private function assertCodeUsableAsStorageField(string $code): void
	{

		if (!preg_match(StorageFieldTable::CODE_PATTERN, $code))
		{
			throw new InvalidDataViewDefinitionException(
				sprintf('DataView column code "%s" is not a valid storage field code.', $code)
			);
		}

		$reserved = StorageItemMapper::getFieldsMap();
		$upperCode = mb_strtoupper($code);
		if (
			array_key_exists($upperCode, $reserved)
			|| in_array($upperCode, array_map('mb_strtoupper', $reserved), true)
		)
		{
			throw new InvalidDataViewDefinitionException(
				sprintf('DataView column code "%s" is reserved by the storage item fields.', $code)
			);
		}
	}

	private function resolveColumnTitle(array $column, string $schemaTitle): string
	{
		$title = (string)($column['title'] ?? '');

		return $title !== '' ? $title : $schemaTitle;
	}

	private function resolveColumnDescription(array $column): ?string
	{
		$description = (string)($column['description'] ?? '');

		return $description !== '' ? $description : null;
	}

	/**
	 * @param array<string, SourceRef> $sourcesByAlias
	 * @param array{leftAlias: string, rightAlias: string, keyPairs: array<int, array{left: string, right: string}>} $plan
	 * @param array<string, SourceSchema> $schemaByAlias
	 * @return array<int, array{code: string, source: string, title: string, type: string, multiple: bool}>
	 */
	private function deriveColumns(array $sourcesByAlias, array $plan, array $schemaByAlias): array
	{

		$rightKeyFields = [];
		foreach ($plan['keyPairs'] as $pair)
		{
			$rightKeyFields[$pair['right']] = true;
		}

		$columns = [];
		$usedCodes = $this->reservedStorageFieldCodes();
		foreach (array_keys($sourcesByAlias) as $alias)
		{
			foreach ($schemaByAlias[$alias]->getFields() as $sourceField)
			{
				if ($alias === $plan['rightAlias'] && isset($rightKeyFields[$sourceField->code]))
				{
					continue;
				}

				$code = $this->deriveStorageFieldCode($sourceField->code, $usedCodes);
				$columns[] = [
					'code' => $code,
					'source' => $alias . '.' . $sourceField->code,
					'title' => $sourceField->title,
					'type' => $sourceField->type,
					'multiple' => $sourceField->multiple,
				];
			}
		}

		return $columns;
	}

	/**
	 * @param array<string, SourceRef> $sourcesByAlias
	 * @param array<string, SourceSchema> $schemaByAlias
	 * @return array<int, array{code: string, source: string, title: string, type: string, multiple: bool}>
	 * @throws InvalidDataViewDefinitionException
	 */
	private function buildAggregateColumns(array $definition, array $sourcesByAlias, array $schemaByAlias): array
	{
		$alias = (string)array_key_first($sourcesByAlias);
		$this->assertAggregatePeriod($definition, $alias);

		$schema = $schemaByAlias[$alias];
		$aggregate = (array)($definition['aggregate'] ?? []);
		$providedCodes = $this->providedColumnCodesBySource($definition);

		$columns = [];
		$usedCodes = [];
		foreach ($this->parseGroupByFields($aggregate, $alias, $schema) as $field)
		{
			$source = $alias . '.' . $field->code;
			$code = $providedCodes[$source] ?? $this->uniqueCode($field->code, $usedCodes);
			$this->assertCodeUsableAsStorageField($code);

			if (isset($usedCodes[$code]))
			{
				throw InvalidDataViewDefinitionException::forField(
					$source,
					sprintf('DataView column code "%s" is duplicated.', $code),
					InvalidDataViewDefinitionException::VIOLATION_COLUMN_DUPLICATE,
				);
			}

			$usedCodes[$code] = true;
			$columns[] = [
				'code' => $code,
				'source' => $source,
				'title' => $field->title,
				'type' => $field->type,
				'multiple' => $field->multiple,
			];
		}

		foreach ($this->parseFunctionColumns($aggregate, $alias, $schema, $usedCodes) as $column)
		{
			$columns[] = $column;
		}

		foreach ((array)($definition['columns'] ?? []) as $column)
		{
			$column = (array)$column;
			if (($column['kind'] ?? null) === 'constant')
			{
				$columns[] = $this->resolveStampColumn(
					$column,
					(string)($column['code'] ?? ''),
					$usedCodes,
				);
			}
		}

		return $columns;
	}

	private function assertAggregatePeriod(array $definition, string $alias): void
	{
		if ((string)($definition['period']['sourceAlias'] ?? '') !== $alias)
		{
			throw new InvalidDataViewDefinitionException(
				'DataView period.sourceAlias must match the aggregate source alias.',
				violation: InvalidDataViewDefinitionException::VIOLATION_PERIOD,
			);
		}

		$this->periodResolver->resolve((array)$definition['period']);
	}

	/**
	 * Result column code the definition already assigned to a source field, keyed by its 'alias.FIELD'
	 * reference. The aggregate branch reuses it for groupBy so a grouped field lands under the same
	 * storage-safe code the projection carries (the client renames reserved codes there); the raw
	 * schema code alone would clash with a reserved storage field.
	 *
	 * @return array<string, string>
	 */
	private function providedColumnCodesBySource(array $definition): array
	{
		$map = [];
		foreach ((array)($definition['columns'] ?? []) as $column)
		{
			$column = (array)$column;
			$code = (string)($column['code'] ?? '');
			$source = (string)($column['source'] ?? '');
			if ($code !== '' && $source !== '' && !isset($map[$source]))
			{
				$map[$source] = $code;
			}
		}

		return $map;
	}

	/**
	 * @return SourceField[]
	 * @throws InvalidDataViewDefinitionException
	 */
	private function parseGroupByFields(array $aggregate, string $alias, SourceSchema $schema): array
	{
		$groupBy = (array)($aggregate['groupBy'] ?? []);
		if ($groupBy === [])
		{
			throw new InvalidDataViewDefinitionException('DataView aggregate requires at least one groupBy field.');
		}

		$fields = [];
		$seen = [];
		foreach ($groupBy as $ref)
		{
			$ref = (string)$ref;
			$field = $this->resolveAggregateField($ref, $alias, $schema);

			if (!$field->joinable)
			{
				throw InvalidDataViewDefinitionException::forField(
					$ref,
					sprintf('DataView aggregate cannot group by a field of type "%s".', $field->type),
				);
			}

			if (isset($seen[$field->code]))
			{
				throw InvalidDataViewDefinitionException::forField(
					$ref,
					'DataView aggregate groupBy field is duplicated.',
				);
			}

			$seen[$field->code] = true;
			$fields[] = $field;
		}

		return $fields;
	}

	/**
	 * @param array<string, bool> $usedCodes
	 * @return array<int, array{code: string, source: string, title: string, type: string, multiple: bool}>
	 * @throws InvalidDataViewDefinitionException
	 */
	private function parseFunctionColumns(array $aggregate, string $alias, SourceSchema $schema, array &$usedCodes): array
	{
		$functions = (array)($aggregate['functions'] ?? []);
		if ($functions === [])
		{
			throw new InvalidDataViewDefinitionException('DataView aggregate requires at least one function.');
		}

		$columns = [];
		foreach ($functions as $function)
		{
			$function = (array)$function;
			$ref = (string)($function['column'] ?? '');
			$field = $this->resolveAggregateField($ref, $alias, $schema);
			$fn = $this->parseAggregateFunction($function, $ref);
			$this->assertFunctionApplicable($fn, $field, $ref);

			$code = (string)($function['code'] ?? '');
			if ($code === '')
			{
				throw InvalidDataViewDefinitionException::forField(
					$ref,
					'DataView aggregate function requires a result column code.',
				);
			}

			$this->assertCodeUsableAsStorageField($code);

			if (isset($usedCodes[$code]))
			{
				throw InvalidDataViewDefinitionException::forField(
					$code,
					sprintf('DataView column code "%s" is duplicated.', $code),
					InvalidDataViewDefinitionException::VIOLATION_COLUMN_DUPLICATE,
				);
			}

			$usedCodes[$code] = true;
			$columns[] = [
				'code' => $code,
				'source' => $alias . '.' . $field->code,
				'title' => $this->resolveColumnTitle($function, $field->title),
				'type' => $fn->resultType($field->type),
				'multiple' => false,
			];
		}

		return $columns;
	}

	/**
	 * @throws InvalidDataViewDefinitionException
	 */
	private function resolveAggregateField(string $ref, string $alias, SourceSchema $schema): SourceField
	{
		[$refAlias, $code] = $this->splitRef($ref);
		if ($refAlias !== $alias)
		{
			throw InvalidDataViewDefinitionException::forField(
				$ref,
				sprintf('DataView aggregate field must reference the source alias "%s".', $alias),
			);
		}

		$field = $schema->getField($code);
		if ($field === null)
		{
			throw InvalidDataViewDefinitionException::forField(
				$ref,
				'DataView aggregate field is missing in the source schema.',
				InvalidDataViewDefinitionException::VIOLATION_FIELD_UNKNOWN,
			);
		}

		return $field;
	}

	/**
	 * @throws InvalidDataViewDefinitionException
	 */
	private function parseAggregateFunction(array $function, string $ref): AggregateFunction
	{
		$fn = AggregateFunction::tryFrom(strtoupper((string)($function['fn'] ?? '')));
		if ($fn === null)
		{
			throw InvalidDataViewDefinitionException::forField(
				$ref,
				sprintf(
					'DataView aggregate function must be one of %s.',
					implode(', ', array_column(AggregateFunction::cases(), 'value')),
				),
			);
		}

		return $fn;
	}

	/**
	 * @throws InvalidDataViewDefinitionException
	 */
	private function assertFunctionApplicable(AggregateFunction $fn, SourceField $field, string $ref): void
	{
		if ($fn->requiresNumericColumn())
		{
			if (!in_array($field->type, [FieldType::INT, FieldType::DOUBLE], true))
			{
				throw InvalidDataViewDefinitionException::forField(
					$ref,
					sprintf(
						'DataView aggregate function "%s" requires a numeric field, got "%s".',
						$fn->value,
						$field->type,
					),
					InvalidDataViewDefinitionException::VIOLATION_AGGREGATE_FN,
				);
			}

			return;
		}

		if (!$field->joinable)
		{
			throw InvalidDataViewDefinitionException::forField(
				$ref,
				sprintf(
					'DataView aggregate function "%s" is not applicable to a field of type "%s".',
					$fn->value,
					$field->type,
				),
				InvalidDataViewDefinitionException::VIOLATION_AGGREGATE_FN,
			);
		}
	}

	/**
	 * @param array<string, SourceRef> $sourcesByAlias
	 * @param array<string, SourceSchema> $schemaByAlias
	 * @return array<int, array{code: string, source: string, title: string, type: string, multiple: bool}>
	 * @throws InvalidDataViewDefinitionException
	 * @throws SourceUnavailableException
	 */
	private function buildProjectColumns(array $definition, array $sourcesByAlias, array $schemaByAlias): array
	{
		$alias = (string)array_key_first($sourcesByAlias);
		$this->assertProjectPeriod($definition, $alias);

		$schema = $schemaByAlias[$alias];
		$provided = (array)($definition['columns'] ?? []);

		return $provided === []
			? $this->deriveProjectColumns($alias, $schema)
			: $this->enrichProjectColumns($provided, $alias, $schema)
		;
	}

	/**
	 * @throws InvalidDataViewDefinitionException
	 */
	private function assertProjectPeriod(array $definition, string $alias): void
	{
		if ((string)($definition['period']['sourceAlias'] ?? '') !== $alias)
		{
			throw new InvalidDataViewDefinitionException(
				'DataView period.sourceAlias must match the project source alias.',
				violation: InvalidDataViewDefinitionException::VIOLATION_PERIOD,
			);
		}

		$this->periodResolver->resolve((array)$definition['period']);
	}

	/**
	 * @return array<int, array{code: string, source: string, title: string, type: string, multiple: bool}>
	 * @throws InvalidDataViewDefinitionException
	 * @throws SourceUnavailableException
	 */
	private function enrichProjectColumns(array $provided, string $alias, SourceSchema $schema): array
	{
		$columns = [];
		$usedCodes = [];
		foreach ($provided as $column)
		{
			$column = (array)$column;
			$code = (string)($column['code'] ?? '');
			if (($column['kind'] ?? null) === 'constant')
			{
				$columns[] = $this->resolveStampColumn($column, $code, $usedCodes);

				continue;
			}

			[$refAlias, $field] = $this->splitRef((string)($column['source'] ?? ''));

			if ($code === '' || $refAlias !== $alias)
			{
				throw new InvalidDataViewDefinitionException(
					'DataView column must reference the project source alias.'
				);
			}

			$this->assertCodeUsableAsStorageField($code);

			if (isset($usedCodes[$code]))
			{
				throw new InvalidDataViewDefinitionException(
					sprintf('DataView column code "%s" is duplicated.', $code),
					violation: InvalidDataViewDefinitionException::VIOLATION_COLUMN_DUPLICATE,
				);
			}

			$sourceField = $schema->getField($field);
			if ($sourceField === null)
			{
				throw SourceUnavailableException::sourceFieldRemoved($field);
			}

			$usedCodes[$code] = true;
			$resolved = [
				'code' => $code,
				'source' => $alias . '.' . $field,
				'title' => $this->resolveColumnTitle($column, $sourceField->title),
				'type' => $sourceField->type,
				'multiple' => $sourceField->multiple,
			];

			$description = $this->resolveColumnDescription($column);
			if ($description !== null)
			{
				$resolved['description'] = $description;
			}

			$columns[] = $resolved;
		}

		return $columns;
	}

	/**
	 * @return array<int, array{code: string, source: string, title: string, type: string, multiple: bool}>
	 */
	private function deriveProjectColumns(string $alias, SourceSchema $schema): array
	{
		$columns = [];
		$usedCodes = $this->reservedStorageFieldCodes();
		foreach ($schema->getFields() as $sourceField)
		{
			$code = $this->deriveStorageFieldCode($sourceField->code, $usedCodes);
			$columns[] = [
				'code' => $code,
				'source' => $alias . '.' . $sourceField->code,
				'title' => $sourceField->title,
				'type' => $sourceField->type,
				'multiple' => $sourceField->multiple,
			];
		}

		return $columns;
	}

	/**
	 * @return array<string, bool> Reserved storage codes keyed by upper-case name.
	 */
	private function reservedStorageFieldCodes(): array
	{
		$reserved = StorageItemMapper::getFieldsMap();

		return array_fill_keys(
			array_map('mb_strtoupper', array_merge(array_keys($reserved), array_values($reserved))),
			true,
		);
	}

	/**
	 * @param array<string, bool> $usedCodes Keyed by upper-case name, updated in place.
	 */
	private function deriveStorageFieldCode(string $baseCode, array &$usedCodes): string
	{
		$code = $baseCode;
		$index = 2;
		while (isset($usedCodes[mb_strtoupper($code)]))
		{
			$code = $baseCode . '_' . $index++;
		}

		$this->assertCodeUsableAsStorageField($code);
		$usedCodes[mb_strtoupper($code)] = true;

		return $code;
	}

	/**
	 * @param array<string, bool> $usedCodes
	 */
	private function uniqueCode(string $baseCode, array $usedCodes): string
	{
		if (!isset($usedCodes[$baseCode]))
		{
			return $baseCode;
		}

		$index = 2;
		while (isset($usedCodes[$baseCode . '_' . $index]))
		{
			$index++;
		}

		return $baseCode . '_' . $index;
	}

	/** @param array<string, string> $existingTypes */
	private function assertWithinFieldLimit(array $columns, array $existingTypes): void
	{
		$codes = [];
		foreach ($columns as $column)
		{
			$codes[$column['code']] = true;
		}

		foreach (array_keys($existingTypes) as $code)
		{
			$codes[$code] = true;
		}

		$max = $this->limitsService->getMaxFieldsPerStorage();
		$total = count($codes);
		if ($total > $max)
		{
			throw new InvalidDataViewDefinitionException(
				sprintf('DataView result storage fields count %d exceeds the storage field limit of %d.', $total, $max)
			);
		}
	}

	/** @return array<string, string> result field types of the storage, keyed by field code */
	private function getExistingFieldTypes(?int $targetStorageTypeId): array
	{
		if ($targetStorageTypeId === null || $targetStorageTypeId <= 0)
		{
			return [];
		}

		$types = [];
		foreach ($this->storageFieldRepository->getByStorageId($targetStorageTypeId, ['*']) as $field)
		{
			$types[(string)$field->getCode()] = (string)$field->getType();
		}

		return $types;
	}

	/**
	 * @return array{0: string, 1: string} [alias, field]
	 * @throws InvalidDataViewDefinitionException
	 */
	private function splitRef(string $ref): array
	{
		$dot = strpos($ref, '.');
		if ($dot === false || $dot === 0 || $dot === strlen($ref) - 1)
		{
			throw new InvalidDataViewDefinitionException(sprintf('Invalid DataView field reference "%s".', $ref));
		}

		return [substr($ref, 0, $dot), substr($ref, $dot + 1)];
	}

	private function assertJoinable(string $key, SourceField $field): void
	{
		if (!$field->joinable)
		{
			throw KeyNotJoinableException::forKey($key, $field->type);
		}

		if (!$field->indexed)
		{
			throw KeyNotIndexedException::forKey($key, $field->type);
		}
	}

	private function keyCategory(string $type): string
	{
		return match ($type)
		{
			FieldType::INT, FieldType::DOUBLE => 'number',
			FieldType::DATE => 'date',
			FieldType::DATETIME => 'datetime',
			FieldType::TIME => 'time',
			default => 'string',
		};
	}

	private function resolveProvider(SourceRef $ref): DataSourceProvider
	{
		$provider = $this->providerRegistry->get($ref->module);
		if ($provider === null)
		{
			throw new SourceUnavailableException(
				sprintf('DataView source provider for module "%s" is unavailable', $ref->module)
			);
		}

		return $provider;
	}

	private function resolveStampColumn(array $column, string $code, array &$usedCodes): array
	{
		if ($code === '')
		{
			throw new InvalidDataViewDefinitionException(
				'DataView stamp column requires a code.',
				violation: InvalidDataViewDefinitionException::VIOLATION_STAMP_INVALID,
			);
		}

		$this->assertCodeUsableAsStorageField($code);
		if (isset($usedCodes[$code]))
		{
			throw new InvalidDataViewDefinitionException(
				sprintf('DataView column code "%s" is duplicated.', $code),
				violation: InvalidDataViewDefinitionException::VIOLATION_COLUMN_DUPLICATE,
			);
		}

		$ref = SourceRef::fromArray((array)($column['constant'] ?? []));
		$provider = $this->resolveProvider($ref);
		if (!$provider instanceof StampConstantProvider)
		{
			throw new SourceUnavailableException(
				sprintf('DataView source provider for module "%s" does not support stamp constants', $ref->module),
			);
		}

		try
		{
			$constant = $provider->resolveStampConstant($ref);
		}
		catch (SourceUnavailableException $exception)
		{
			throw new InvalidDataViewDefinitionException(
				$exception->getMessage(),
				violation: InvalidDataViewDefinitionException::VIOLATION_STAMP_INVALID,
			);
		}

		$usedCodes[$code] = true;
		$resolved = [
			'code' => $code,
			'kind' => 'constant',
			'constant' => [
				'module' => $ref->module,
				'entity' => $ref->entity,
				'params' => $ref->params,
			],
			'title' => $this->resolveColumnTitle($column, $constant->title),
			'type' => $constant->type,
			'multiple' => false,
		];

		$description = $this->resolveColumnDescription($column);
		if ($description !== null)
		{
			$resolved['description'] = $description;
		}

		return $resolved;
	}
}
