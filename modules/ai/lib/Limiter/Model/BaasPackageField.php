<?php declare(strict_types=1);

namespace Bitrix\AI\Limiter\Model;

enum BaasPackageField: string
{
	case ID = 'ID';
	case DATE_START = 'DATE_START';
	case DATE_EXPIRED = 'DATE_EXPIRED';
}
