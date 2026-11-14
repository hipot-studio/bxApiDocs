<?php

namespace Bitrix\Rest\V3\Dto\Meta;

/**
 * Marks a field that has no value, since null is a valid field value.
 *
 * @internal
 */
enum AbsentValue
{
	case Marker;
}
