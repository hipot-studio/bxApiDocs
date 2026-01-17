<?php

namespace Bitrix\Rest\V3\Structure\Filtering;

enum Logic: string
{
	case And = 'and';

	case Or = 'or';
}
