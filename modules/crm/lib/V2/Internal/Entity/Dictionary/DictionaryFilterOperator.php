<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Dictionary;

/**
 * The operators a condition of {@see DictionaryFilter} may carry. The set is the one the common REST
 * filter offers, minus what no field of a dictionary supports: there is no substring operator here,
 * because the common filter has none either.
 *
 * Which of them a given field accepts is the field's own answer, {@see DictionaryField::supportsOperator()}.
 *
 * @internal
 */
enum DictionaryFilterOperator: string
{
	case Equal = '=';
	case NotEqual = '!=';
	case Greater = '>';
	case GreaterOrEqual = '>=';
	case Less = '<';
	case LessOrEqual = '<=';
	case In = 'in';
	case Between = 'between';

	/** The value is a set of values of the field rather than a single one. */
	public function expectsList(): bool
	{
		return $this === self::In;
	}

	/** The value is the pair of bounds of a range. */
	public function expectsRange(): bool
	{
		return $this === self::Between;
	}
}
