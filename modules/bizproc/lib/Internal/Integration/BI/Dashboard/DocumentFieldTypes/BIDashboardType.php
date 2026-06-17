<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\BI\Dashboard\DocumentFieldTypes;

use Bitrix\Bizproc\BaseType\IntType;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;

class BIDashboardType extends IntType
{
	public static function getName(): string
	{
		return (string)Loc::getMessage('BIZPROC_INTERNAL_INTEGRATION_BI_DASHBOARD_NAME');
	}

	public static function getType(): string
	{
		return 'bi_dashboard';
	}

	public static function isTypeAvailable(): bool
	{
		return Option::get('bizproc', 'bitrix_ai_bi_dashboard_available', 'N') === 'Y'
			&& isModuleInstalled('biconnector')
		;
	}
}