<?php

namespace Bitrix\Crm\Import\ImportEntityFields;

use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\StringValueMapper;
use Bitrix\Crm\UtmTable;

final class UtmField implements ImportEntityFieldInterface
{
	public function __construct(
		private readonly string $code,
	)
	{
	}

	public function getId(): string
	{
		return $this->code;
	}

	public function getCaption(): string
	{
		return UtmTable::getCodeNames()[$this->code];
	}

	public function isRequired(): bool
	{
		return false;
	}

	public function isReadonly(): bool
	{
		return false;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new StringValueMapper($this->getId()))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}
