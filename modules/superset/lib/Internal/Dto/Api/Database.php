<?php

namespace Bitrix\Superset\Internal\Dto\Api;

class Database
{
	public function __construct(
		public string $databaseName,
		public string $sqlAlchemyUri,
		public ?string $cacheTimeout,
		public bool $exposeInSqlLab,
		public bool $allowRunAsync,
		public bool $allowCtas,
		public bool $allowCvas,
		public bool $allowDml,
		public bool $allowFileUpload,
		public Extra $extra,
		public string $uuid,
		public string $version = '1.0.0'
	)
	{}
}

class Extra
{
	public function __construct(
		public \stdClass $extra,
	)
	{}
}
