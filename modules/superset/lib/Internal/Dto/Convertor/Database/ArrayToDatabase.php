<?php

namespace Bitrix\Superset\Internal\Dto\Convertor\Database;

use Bitrix\Superset\Internal\Dto\Api;

class ArrayToDatabase
{
	public static function convert(array $fields): Api\Database
	{
		try
		{
			$extra = json_decode($fields['extra'], false, 512, JSON_THROW_ON_ERROR);
		}
		catch (\JsonException)
		{
			$extra = new \stdClass();
		}

		return new Api\Database(
			databaseName: $fields['database_name'],
			sqlAlchemyUri: $fields['sqlalchemy_uri'],
			cacheTimeout: $fields['cache_timeout'],
			exposeInSqlLab: $fields['expose_in_sqllab'],
			allowRunAsync: $fields['allow_run_async'],
			allowCtas: $fields['allow_ctas'],
			allowCvas: $fields['allow_cvas'],
			allowDml: $fields['allow_dml'],
			allowFileUpload: $fields['allow_file_upload'],
			extra: new Api\Extra($extra),
			uuid: $fields['uuid'],
		);
	}
}
