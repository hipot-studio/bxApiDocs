<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Debugger;

enum LogLevel: string
{
	case Emergency = 'emergency';
	case Alert = 'alert';
	case Critical = 'critical';
	case Error = 'error';
	case Warning = 'warning';
	case Notice = 'notice';
	case Info = 'info';
	case Debug = 'debug';
}
