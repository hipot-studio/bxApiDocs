<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Operation;

use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;

/**
 * @internal
 */
final class CategoryDependentFieldException extends ArgumentException
{
	public function __construct(string $fieldName)
	{
		parent::__construct("Item field '{$fieldName}' is disabled in the category.", $fieldName);
	}

	public function getError(): Error
	{
		return new Error(
			CategoryError::FIELD_NOT_WRITABLE->getMessage(),
			CategoryError::FIELD_NOT_WRITABLE->value,
			['field' => $this->getFieldName()],
		);
	}

	public function getFieldName(): string
	{
		return (string)$this->getParameter();
	}
}
