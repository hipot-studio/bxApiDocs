<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler\Field;

use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\DateFieldAssembler;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Type;

final class TimestampFieldAssembler extends DateFieldAssembler
{
	public function __construct(
		array $columnIds,
		?Settings $settings = null,
	)
	{
		parent::__construct(
			$columnIds,
			$settings,
			timeFormat: self::TIME_FORMAT_LONG,
		);
	}

	protected function prepareColumn($value)
	{
		if ($value instanceof Type\DateTime)
		{
			return parent::prepareColumn($value);
		}

		if ($value === null || $value === '')
		{
			return '';
		}

		return htmlspecialcharsbx((string)$value);
	}
}
