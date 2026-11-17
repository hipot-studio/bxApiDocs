<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Filter\Field;

/**
 * Whether the filled member fields are matched all at once or any of them.
 *
 * One operator for the whole group of member fields (responsible, accomplice, auditor, originator) instead of
 * one per field, and only meaningful for the fields in Mode::EQUAL; AND is the default and is never persisted.
 */
enum MemberUnion: string
{
	case AND = 'and';
	case OR = 'or';

	public const SATELLITE_KEY = 'MEMBER_fieldLogic';
}
