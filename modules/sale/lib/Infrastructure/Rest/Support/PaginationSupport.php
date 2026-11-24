<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Support;

use Bitrix\Rest\V3\Structure\PaginationStructure;

/**
 * Limit and offset of a list request.
 *
 * The default limit applies even when the client sends no pagination at all: an unbounded
 * selection must never leave the layer.
 */
final class PaginationSupport
{
	public static function getLimit(?PaginationStructure $pagination): int
	{
		return $pagination?->getLimit() ?? PaginationStructure::DEFAULT_LIMIT;
	}

	public static function getOffset(?PaginationStructure $pagination): int
	{
		return $pagination?->getOffset() ?? 0;
	}

	public static function isLimitAcceptable(?PaginationStructure $pagination): bool
	{
		return self::isAcceptableLimit(self::getLimit($pagination));
	}

	/**
	 * The single rule for a selection limit, shared with the cursor ({@see TailSupport}).
	 *
	 * Both structures of the platform check the sign of the limit *before* casting it to int
	 * (`Rest\V3\Structure\PaginationStructure::create()` refuses `<= 0`,
	 * `Rest\V3\Structure\CursorStructure::create()` refuses `== 0`, and both then take
	 * `min((int)$limit, MAX_LIMIT)`), so a fractional limit such as 0.5 passes their check and
	 * arrives here as 0, and a negative cursor limit arrives intact. A limit that is not positive
	 * leaves the ORM query without a LIMIT at all (`Main\ORM\Query\Query::buildQuery()` adds the
	 * top clause only when the limit is positive), handing out the whole table — and the offset
	 * with it. The check therefore belongs on this side, after the cast the platform already made.
	 */
	public static function isAcceptableLimit(int $limit): bool
	{
		return $limit > 0;
	}
}
