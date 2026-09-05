<?php

namespace Bitrix\Main\Config\Feature;

enum RulePolicy: string
{
	case ALLOW = 'allow';
	case DENY = 'deny';
}
