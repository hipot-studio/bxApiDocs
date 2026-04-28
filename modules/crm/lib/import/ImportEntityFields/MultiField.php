<?php

namespace Bitrix\Crm\Import\ImportEntityFields;

use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Main\Type\Contract\Arrayable;
use CCrmFieldMulti;

final class MultiField implements ImportEntityFieldInterface, Arrayable
{
	private const MULTIPLE_VALUE_DELIMITER = ',';

	public function __construct(
		private readonly string $id,
		private readonly string $caption,
		private readonly string|bool $sort,
		private readonly bool $default,
		private readonly bool $editable,
		private readonly string $type,
	)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getCaption(): string
	{
		return $this->caption;
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
		$columnId = $fieldBindings->getColumnIndexByFieldId($this->getId());
		if ($columnId === null)
		{
			return FieldProcessResult::skip();
		}

		$value = $row[$columnId] ?? null;
		if (empty($value))
		{
			return FieldProcessResult::skip();
		}

		$value = explode(self::MULTIPLE_VALUE_DELIMITER, $value);
		$importItemFields[$this->getId()] = $value;

		return FieldProcessResult::success();
	}

	/**
	 * @param array|null $header
	 * @return self|null
	 *
	 * @see CCrmFieldMulti::ListAddHeaders
	 */
	public static function tryFromHeader(?array $header): ?self
	{
		return new self(
			$header['id'],
			$header['name'],
			$header['sort'],
			$header['default'],
			$header['editable'],
			$header['type'],
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->caption,
			'sort' => $this->sort,
			'default' => $this->default,
			'editable' => $this->editable,
			'type' => $this->type,
		];
	}
}
