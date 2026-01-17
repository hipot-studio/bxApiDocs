<?php

namespace Bitrix\Rest\V3\Attribute;

enum RequiredGroup: string
{
	case Add = 'add';
	case Update = 'update';
	case Default = 'default';
}
