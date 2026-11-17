<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Integration\CRM\Grid;

/**
 * Everything a task-list cell is allowed to know about one CRM binding. Free of any V2 type, so a cell
 * renders from this layer alone; a value the grid map does not carry is never readable.
 */
interface CrmItemGridDataInterface
{
	public function isReadable(): bool;

	/**
	 * The element is gone: deleted, or its type is not supported.
	 */
	public function isNotFound(): bool;

	/**
	 * The real title for a readable element, the depersonalized one otherwise.
	 */
	public function getDisplayTitle(): string;

	/**
	 * Address of the CRM element; null unless the element is readable.
	 */
	public function getUrl(): ?string;

	/**
	 * Type abbreviation of the stored value: `D`, `C`, `CO`, `L`, `SI` or `T{hex}` for a smart process.
	 */
	public function getAbbreviation(): string;

	public function getTypeId(): int;

	public function getEntityId(): int;

	/**
	 * The raw UF_CRM_TASK value (`D_15`).
	 */
	public function getValue(): string;
}
