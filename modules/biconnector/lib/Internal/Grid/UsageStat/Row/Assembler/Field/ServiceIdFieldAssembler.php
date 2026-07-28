<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;

final class ServiceIdFieldAssembler extends FieldAssembler
{
	protected function prepareColumn($value)
	{
		if ($value === null || $value === '')
		{
			return '';
		}

		$localized = Loc::getMessage('BIC_USAGE_STAT_GRID_ROW_SERVICE_ID_' . mb_strtoupper((string)$value));

		return ($localized === null || $localized === '') ? htmlspecialcharsbx((string)$value) : $localized;
	}
}
