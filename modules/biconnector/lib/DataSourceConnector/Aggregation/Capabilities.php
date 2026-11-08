<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

use Bitrix\BIConnector\DB\BiAggregationDialectInterface;

/**
 * The `capabilities.aggregation` block of `?desc&caps=1` (design §2.1).
 *
 * The fingerprint is content addressed: it is the SHA-256 of the canonical form of the block
 * without the fingerprint field itself. Any change of the content - a different source behind the
 * same table name, a flag, an excluded column - changes it, and no persistent counter, atomic
 * increment or cluster coordination is needed.
 */
final class Capabilities
{
	public const PROTOCOL_VERSIONS = [1];

	public const FUNCTION_COUNT = 'COUNT';
	public const FUNCTION_COUNT_DISTINCT = 'COUNT_DISTINCT';
	public const FUNCTION_SUM = 'SUM';
	public const FUNCTION_SUM_DISTINCT = 'SUM_DISTINCT';
	public const FUNCTION_AVG = 'AVG';
	public const FUNCTION_AVG_DISTINCT = 'AVG_DISTINCT';
	public const FUNCTION_MIN = 'MIN';
	public const FUNCTION_MAX = 'MAX';

	/**
	 * Order of the dictionary in design §2.1.
	 *
	 * Canonicalization sorts object keys but preserves array order, so this order is part of the
	 * capabilities fingerprint and has to reproduce the normative example byte for byte.
	 */
	private const FUNCTION_ORDER = [
		self::FUNCTION_COUNT,
		self::FUNCTION_COUNT_DISTINCT,
		self::FUNCTION_SUM,
		self::FUNCTION_SUM_DISTINCT,
		self::FUNCTION_AVG,
		self::FUNCTION_AVG_DISTINCT,
		self::FUNCTION_MIN,
		self::FUNCTION_MAX,
	];

	/**
	 * @param string[] $functions
	 * @param string[] $dateTruncUnits
	 * @param string[] $excludedColumns
	 */
	private function __construct(
		public readonly bool $supported,
		public readonly array $protocolVersions = [],
		public readonly string $rowSetSemantics = '',
		public readonly array $functions = [],
		public readonly array $dateTruncUnits = [],
		public readonly bool $having = false,
		public readonly bool $groupLimit = false,
		public readonly bool $orderBy = false,
		public readonly array $excludedColumns = [],
		public readonly ?DialectSemantics $semantics = null,
		public readonly string $scopeId = '',
		public readonly ?WhereCapabilities $where = null,
	)
	{
	}

	public static function unsupported(): self
	{
		return new self(false);
	}

	/**
	 * Builds the block for a dataset served by the given dialect.
	 *
	 * A null dialect means the connection is not SQL or its helper does not implement the
	 * aggregation contract, which is reported as `supported: false`.
	 */
	public static function build(
		?BiAggregationDialectInterface $dialect,
		DatasetSchema $schema,
		string $scopeId,
	): self
	{
		if ($dialect === null || $scopeId === '')
		{
			return self::unsupported();
		}

		$rowSetSemantics = $schema->getRowSetSemantics();
		if ($rowSetSemantics !== DatasetSchema::ROW_SET_RAW)
		{
			// The plugin refuses anything but RAW anyway; answering `false` keeps the block minimal.
			return self::unsupported();
		}

		return new self(
			supported: true,
			protocolVersions: self::PROTOCOL_VERSIONS,
			rowSetSemantics: $rowSetSemantics,
			functions: self::buildFunctions($dialect),
			dateTruncUnits: DateTruncSpec::UNITS,
			having: true,
			groupLimit: true,
			orderBy: true,
			excludedColumns: self::buildExcludedColumns($dialect, $schema),
			semantics: $dialect->getSemantics(),
			scopeId: $scopeId,
			where: WhereCapabilities::build($dialect, $schema),
		);
	}

	/**
	 * Builds the block out of the declaration of an external application (ALG-01).
	 *
	 * The rule is "the portal accepts only what it can check itself": every claim is intersected with
	 * what the portal knows, and the order of every list is the order of the portal - the declaration
	 * may not reorder a list, because that would change the fingerprint.
	 *
	 * @param array<string, string> $mapFields EXTERNAL_CODE => NAME of the same table. Supplied by the
	 *     caller, so that the aggregation layer never reads the external source catalog itself.
	 */
	public static function fromDeclaration(
		ExternalCapabilitiesDeclaration $declaration,
		DatasetSchema $schema,
		array $mapFields,
		string $scopeId,
	): self
	{
		// An empty or malformed declaration shares no protocol version with the portal.
		$protocolVersions = self::intersectOrdered(self::PROTOCOL_VERSIONS, $declaration->protocolVersions);
		if ($protocolVersions === [])
		{
			return self::unsupported();
		}

		$rowSetSemantics = $schema->getRowSetSemantics();
		if ($rowSetSemantics !== DatasetSchema::ROW_SET_RAW)
		{
			return self::unsupported();
		}

		if ($scopeId === '')
		{
			return self::unsupported();
		}

		$functions = self::buildDeclaredFunctions($declaration);
		if ($functions === [])
		{
			return self::unsupported();
		}

		return new self(
			supported: true,
			// The intersection is published and not the constant of the portal: the published block feeds
			// the version gate, and a version the application never claimed must not reach it.
			protocolVersions: $protocolVersions,
			rowSetSemantics: $rowSetSemantics,
			functions: $functions,
			dateTruncUnits: self::intersectOrdered(DateTruncSpec::UNITS, $declaration->dateTruncUnits),
			having: $declaration->having,
			groupLimit: $declaration->groupLimit,
			orderBy: $declaration->orderBy,
			excludedColumns: self::buildDeclaredExcludedColumns($schema, $declaration, $mapFields),
			semantics: new DialectSemantics(
				binaryStringOperations: $declaration->binaryStringOperations,
				floatNanOrderingCompatible: $declaration->floatNanOrderingCompatible,
			),
			scopeId: $scopeId,
			where: WhereCapabilities::forExternal($declaration, $mapFields),
		);
	}

	public function isColumnExcluded(string $name): bool
	{
		return in_array($name, $this->excludedColumns, true);
	}

	public function supportsFunction(string $function, bool $distinct): bool
	{
		$name = match ($function)
		{
			AggregateItem::COUNT => $distinct ? self::FUNCTION_COUNT_DISTINCT : self::FUNCTION_COUNT,
			AggregateItem::SUM => $distinct ? self::FUNCTION_SUM_DISTINCT : self::FUNCTION_SUM,
			AggregateItem::AVG => $distinct ? self::FUNCTION_AVG_DISTINCT : self::FUNCTION_AVG,
			AggregateItem::MIN => self::FUNCTION_MIN,
			AggregateItem::MAX => self::FUNCTION_MAX,
			default => null,
		};

		return $name !== null && in_array($name, $this->functions, true);
	}

	public function supportsTruncUnit(string $unit): bool
	{
		return in_array($unit, $this->dateTruncUnits, true);
	}

	/**
	 * The block without the fingerprint field, which is what the fingerprint is computed over.
	 */
	public function toArrayWithoutFingerprint(): array
	{
		if (!$this->supported)
		{
			return ['supported' => false];
		}

		$block = [
			'supported' => true,
			'protocolVersions' => array_values($this->protocolVersions),
			'rowSetSemantics' => $this->rowSetSemantics,
			'functions' => array_values($this->functions),
			'dateTruncUnits' => array_values($this->dateTruncUnits),
			'having' => $this->having,
			'groupLimit' => $this->groupLimit,
			'orderBy' => $this->orderBy,
			'excludedColumns' => array_values($this->excludedColumns),
			'semantics' => $this->semantics?->toArray() ?? [],
			'scopeId' => $this->scopeId,
		];

		// Additive: a plugin that does not read the block keeps its previous behaviour, and its absence
		// forbids narrowing the residual altogether.
		if ($this->where !== null)
		{
			$block['where'] = $this->where->toArray();
		}

		return $block;
	}

	public function getFingerprint(): string
	{
		return CanonicalJson::fingerprint($this->toArrayWithoutFingerprint());
	}

	public function toArray(): array
	{
		if (!$this->supported)
		{
			return ['supported' => false];
		}

		$block = $this->toArrayWithoutFingerprint();
		$block['capsFingerprint'] = $this->getFingerprint();

		return $block;
	}

	/**
	 * @return string[]
	 */
	private static function buildFunctions(BiAggregationDialectInterface $dialect): array
	{
		$exactCountDistinct = $dialect->supportsExactCountDistinct();

		$functions = [];
		foreach (self::FUNCTION_ORDER as $function)
		{
			// An approximate COUNT(DISTINCT) must never be advertised: it would be a wrong number.
			if ($function === self::FUNCTION_COUNT_DISTINCT && !$exactCountDistinct)
			{
				continue;
			}

			$functions[] = $function;
		}

		return $functions;
	}

	/**
	 * Columns that take no part in a delegated aggregation in any role (design §2.1, §6.5).
	 *
	 * @return string[]
	 */
	private static function buildExcludedColumns(
		BiAggregationDialectInterface $dialect,
		DatasetSchema $schema,
	): array
	{
		$excluded = [];
		foreach (array_keys($schema->getRawFields()) as $name)
		{
			$name = (string)$name;

			if (self::isExcludedBySchema($schema, $name))
			{
				$excluded[] = $name;

				continue;
			}

			if ($schema->getFieldWireType($name) !== 'BOOLEAN')
			{
				continue;
			}

			// A `bool` field on a character carrier has no truth table anywhere in the code, so no
			// normalizing expression can be derived and the column is excluded by name.
			$wireExpression = $dialect->getBooleanWireExpression('x', $schema->getFieldNativeSqlType($name));
			if ($wireExpression === null)
			{
				$excluded[] = $name;
			}
		}

		sort($excluded);

		return $excluded;
	}

	/**
	 * Reasons a column takes no part in a delegated aggregation that follow from the schema alone, with
	 * no dialect involved.
	 */
	private static function isExcludedBySchema(DatasetSchema $schema, string $name): bool
	{
		return $schema->hasCallback($name) || $schema->isNonAggregatableType($name);
	}

	/**
	 * @return string[]
	 */
	private static function buildDeclaredFunctions(ExternalCapabilitiesDeclaration $declaration): array
	{
		$functions = [];
		foreach (self::FUNCTION_ORDER as $function)
		{
			if (!in_array($function, $declaration->functions, true))
			{
				continue;
			}

			// An approximate COUNT(DISTINCT) must never be advertised: it would be a wrong number. The
			// claim is not taken on trust from the application either.
			if ($function === self::FUNCTION_COUNT_DISTINCT && !$declaration->exactCountDistinct)
			{
				continue;
			}

			$functions[] = $function;
		}

		return $functions;
	}

	/**
	 * Columns excluded by the schema plus the ones the application declared, translated into dataset
	 * field names (ALG-01).
	 *
	 * The boolean carrier is not judged here: there is no dialect to derive a normalizing expression
	 * from, and the wire values of this channel are produced by the application itself, which excludes
	 * a column it cannot normalize by declaring it.
	 *
	 * @param array<string, string> $mapFields
	 *
	 * @return string[]
	 */
	private static function buildDeclaredExcludedColumns(
		DatasetSchema $schema,
		ExternalCapabilitiesDeclaration $declaration,
		array $mapFields,
	): array
	{
		$excluded = [];
		foreach (array_keys($schema->getRawFields()) as $name)
		{
			$name = (string)$name;

			if (self::isExcludedBySchema($schema, $name))
			{
				$excluded[] = $name;
			}
		}

		foreach ($declaration->translateExcludedColumns($mapFields) as $name)
		{
			if (!in_array($name, $excluded, true))
			{
				$excluded[] = $name;
			}
		}

		sort($excluded);

		return $excluded;
	}

	/**
	 * Intersection that keeps the order of the portal: canonicalization preserves array order, so the
	 * order of the declaration must never reach the block.
	 *
	 * @param array<int, int|string> $portalOrder
	 * @param array<int, int|string> $declared
	 *
	 * @return array<int, int|string>
	 */
	private static function intersectOrdered(array $portalOrder, array $declared): array
	{
		$items = [];
		foreach ($portalOrder as $item)
		{
			if (in_array($item, $declared, true))
			{
				$items[] = $item;
			}
		}

		return $items;
	}
}
