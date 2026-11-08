<?php

namespace Bitrix\BIConnector\DataSourceConnector;
final class ConnectorDto
{
	public function __construct(
		public readonly array $schema,
		public readonly string $query,
		public readonly array $queryRowCallbacks,
		public readonly array $filter,
		public readonly bool $filtersApplied,
		public readonly array $shadowFields,
		public readonly bool $aggregationApplied = false,
		/** SHA-256 of the canonical form of the received aggregation block; null when there was none. */
		public readonly ?string $aggregationFingerprint = null,
		/** SHA-256 of the canonical form of the WHERE that actually compiled (design §5.11.3). */
		public readonly ?string $whereFingerprint = null,
		/**
		 * Names of the dataset fields the query touched, as the usage log should name them.
		 *
		 * The answer of a delegated aggregation is described by protocol aliases, and an alias says
		 * nothing to a person reading the usage statistics. The field names travel here instead. Empty
		 * means the schema already names the fields, which is the case on the raw path.
		 *
		 * @var string[]
		 */
		public readonly array $usageStatFields = [],
	)
	{
	}

	/**
	 * @return array
	 */
	public function getColumns(): array
	{
		return array_column($this->schema, 'ID');
	}


	/**
	 * @param string $code
	 * @return mixed
	 */
	public function getFilterValue(string $code): mixed
	{
		return $this->filter[$code] ?? null;
	}
}
