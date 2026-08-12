<?php

namespace Bitrix\Superset\Internal\Dto\Convertor\Database;

use Bitrix\Superset\Internal\Dto\Api\Database;
use Symfony\Component\Yaml\Yaml;

class DatabaseToYaml
{
	public static function convert(Database $database): string
	{
		$databaseFields = [
			'database_name' => $database->databaseName,
			'sqlalchemy_uri' => $database->sqlAlchemyUri,
			'cache_timeout' => $database->cacheTimeout,
			'expose_in_sqllab' => $database->exposeInSqlLab,
			'allow_run_async' => $database->allowRunAsync,
			'allow_ctas' => $database->allowCtas,
			'allow_cvas' => $database->allowCvas,
			'allow_dml' => $database->allowDml,
			'allow_file_upload' => $database->allowFileUpload,
			'extra' => $database->extra->extra,
			'uuid' => $database->uuid,
			'version' => $database->version,
		];

		return Yaml::dump($databaseFields, flags: Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
	}
}
