<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Requisite;

use Bitrix\Main\ArgumentException;

/**
 * A write asked for a field the preset of the requisite does not declare: the jurisdiction the preset
 * stands for has no such legal detail, so a requisite created on it means nothing by that field.
 *
 * The refusal is ours alone - legacy has no such check at all and writes the value into the column - and it
 * carries the offending field itself, because a client that cannot tell which field of its call was refused
 * cannot fix the call.
 *
 * Only the fields a preset decides about reach this refusal
 * ({@see \Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteColumnMap::isFieldOfPreset()}): the
 * properties a requisite has as a record of the portal - its name, its own preset, its activity - belong
 * to no composition of any preset and are not asked about.
 *
 * Thrown for a caller a refused field is a defect of, and collected into the list of refusals for the
 * caller it is an answer to - see {@see \Bitrix\Crm\V2\Internal\Service\Requisite\RequisiteFieldValidator}.
 *
 * @internal
 */
final class FieldNotInPresetException extends ArgumentException
{
	public function __construct(string $fieldName, int $presetId)
	{
		parent::__construct("Requisite field '{$fieldName}' is not declared by the preset {$presetId}.", $fieldName);
	}

	public function getFieldName(): string
	{
		return (string)$this->getParameter();
	}
}
