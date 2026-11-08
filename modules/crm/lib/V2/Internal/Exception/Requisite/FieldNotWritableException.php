<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Requisite;

use Bitrix\Main\ArgumentException;

/**
 * A write asked for a field a requisite does not take from the caller: one the portal fills in itself
 * (the moments and the authors of the record), one that is an invariant of the owner rather than a value
 * of the record (the type of the owner), one the creation takes and the change of a requisite does not
 * (the owner), or a name the area does not know at all.
 *
 * Which fields those are is the write contract of the group and not a rule of the storage, so the
 * composition lives with the rule that applies it
 * ({@see \Bitrix\Crm\V2\Internal\Service\Requisite\RequisiteFieldValidator}) rather than in the map of
 * columns.
 *
 * Nothing has been written when it is thrown, and it carries the offending field itself: a caller that
 * has to guess which of the fields it named was the refused one cannot correct the call.
 *
 * @internal
 */
final class FieldNotWritableException extends ArgumentException
{
	public function __construct(string $fieldName)
	{
		parent::__construct("Requisite field '{$fieldName}' is not writable by this write.", $fieldName);
	}

	public function getFieldName(): string
	{
		return (string)$this->getParameter();
	}
}
