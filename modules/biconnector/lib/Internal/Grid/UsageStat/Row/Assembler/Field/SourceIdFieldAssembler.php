<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler\Field;

use Bitrix\BIConnector\Public\Provider\UsageStat\UsageStatProvider;
use Bitrix\Main\Grid\Row\FieldAssembler;

final class SourceIdFieldAssembler extends FieldAssembler
{
	public function __construct(array $columnIds)
	{
		parent::__construct($columnIds);
	}

	protected function prepareColumn($value)
	{
		if ($value === null || $value === '')
		{
			return '';
		}

		$provider = new UsageStatProvider();
		$tables = $provider->getUsedTables();
		if (isset($tables[$value]))
		{
			return htmlspecialcharsbx((string)$tables[$value]);
		}

		return htmlspecialcharsbx((string)$value);
	}
}
