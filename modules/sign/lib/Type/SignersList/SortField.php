<?php

namespace Bitrix\Sign\Type\SignersList;

/**
 * Sortable columns of the signers list grid (bitrix:sign.b2e.signers.list).
 *
 * String backing values are ORM field paths passed to addOrder(), so an
 * untrusted sort request can only resolve to a representable field path and
 * never a raw string injected into ORDER BY.
 */
enum SortField: string
{
	case Title = 'TITLE';
	case DateModify = 'DATE_MODIFY';
	case Id = 'ID';
}
