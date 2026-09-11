<?php

namespace Bitrix\Sign\Type;

/**
 * Sortable columns of the company safe grid (bitrix:sign.document.list, type=safe).
 *
 * String backing values are ORM field paths passed to setOrder(), so an
 * untrusted sort request can only resolve to a representable field path and
 * never a raw string injected into ORDER BY.
 */
enum MySafeSortField: string
{
	case DocumentTitle = 'DOCUMENT.TITLE';
	case DateSign = 'DATE_SIGN';
}
