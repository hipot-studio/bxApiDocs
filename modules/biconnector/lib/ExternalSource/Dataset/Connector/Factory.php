<?php

namespace Bitrix\BIConnector\ExternalSource\Dataset\Connector;

use Bitrix\Main\SystemException;
use Bitrix\BIConnector\DataSourceConnector\FieldCollection;
use Bitrix\BIConnector\DataSourceConnector;
use Bitrix\BIConnector\ExternalSource\Type;
use Bitrix\Main\DB\Connection;

final class Factory
{
	public static function getConnector(
		Type $type,
		string $name,
		FieldCollection $fields,
		array $datasetInfo,
		?Connection $connection = null,
	): DataSourceConnector\Connector\Base
	{
		return match ($type)
		{
			Type::Csv => new Csv($name, $fields, $datasetInfo),
			Type::Source1C => new Source1C($name, $fields, $datasetInfo),
			Type::Rest => new Rest($name, $fields, $datasetInfo),
			Type::Mysql => new Mysql($name, $fields, $datasetInfo, $connection),
			Type::Pgsql => new Pgsql($name, $fields, $datasetInfo, $connection),
			default => throw new SystemException("Unknown type {$type->value}"),
		};
	}
}
