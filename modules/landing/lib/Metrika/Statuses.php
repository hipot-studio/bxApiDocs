<?php
declare(strict_types=1);

namespace Bitrix\Landing\Metrika;

enum Statuses: string
{
	case Success = 'success';
	case Error = 'error';
	case ErrorB24 = 'error_b24';
	case ErrorMarket = 'error_market';
	case ErrorTurnedOff = 'error_turnedoff';
}
