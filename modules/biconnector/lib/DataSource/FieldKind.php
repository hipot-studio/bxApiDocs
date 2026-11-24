<?php

namespace Bitrix\BIConnector\DataSource;

/**
 * Optional keys of a field description (`FIELDS[code]`) that tell row rules what the field holds.
 *
 * A field without the kind key is an ordinary one. A `department` field with an owner field means
 * "department of the employee from the owner field"; no other comparison mode exists.
 * The keys are not read by FieldDto, so they change neither the data output nor `?desc`.
 */
final class FieldKind
{
	public const KEY_KIND = 'FIELD_KIND';
	public const KEY_OWNER = 'OWNER_FIELD';

	public const EMPLOYEE = 'employee';
	public const DEPARTMENT = 'department';
	public const DICTIONARY = 'dictionary';
}
