<?php

namespace Bitrix\Crm\Import\Contract;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;

interface ImportEntityFieldInterface
{
	/**
	 * Return field identifier
	 *
	 * @return string
	 */
	public function getId(): string;

	/**
	 * Return field caption
	 *
	 * @return string
	 */
	public function getCaption(): string;

	/**
	 * Return true if field is required
	 *
	 * @return bool
	 */
	public function isRequired(): bool;

	/**
	 * If true, it prevents the user from selecting another column to retrieve data in interfaces.
	 *
	 * @return bool
	 */
	public function isReadonly(): bool;

	/**
	 * Find value from import row by fieldBindings, validate value and set into item
	 *
	 * @param array $importItemFields
	 * @param FieldBindings $fieldBindings
	 * @param array $row
	 * @return FieldProcessResult
	 */
	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult;
}
