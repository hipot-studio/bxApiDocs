<?php

namespace Bitrix\HumanResources\Type\HcmLink\Monitoring;

enum ExchangeHealth: string
{
	case NO_DATA = 'noData';
	case OK = 'ok';
	case UNFINISHED = 'unfinished';
	case PARTIAL = 'partial';
	case BROKEN = 'broken';
}
