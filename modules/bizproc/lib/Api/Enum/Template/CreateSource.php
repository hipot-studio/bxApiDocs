<?php

namespace Bitrix\Bizproc\Api\Enum\Template;

enum CreateSource: string //values are limited in DB varchar 32
{
	case User = 'USER';
	case Scenario = 'SCENARIO';
}
