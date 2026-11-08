<?php

namespace Bitrix\BIConnector\ExternalSource\Dataset\Connector;

use Bitrix\BIConnector\DataSourceConnector\Connector\Sql;

final class Csv extends Sql
{
	protected const ANALYTIC_TAG_DATASET = 'CSV';
}
