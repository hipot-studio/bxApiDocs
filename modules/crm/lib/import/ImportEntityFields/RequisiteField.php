<?php

namespace Bitrix\Crm\Import\ImportEntityFields;

use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Enum\RequisiteFieldType;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Requisite\ImportHelper;
use Bitrix\Main\Type\Contract\Arrayable;

final class RequisiteField implements ImportEntityFieldInterface, Arrayable
{
	private const REQUIRED_FIELD_IDS = [
		'RQ_NAME',
		'RQ_ID',
		'RQ_PRESET_NAME',
		'RQ_PRESET_ID',
		'BD_NAME',
		'BD_ID',
	];

	public function __construct(
		private readonly string $id,
		private readonly string $caption,
		private readonly RequisiteFieldType $group,
		private readonly string $field,
		private readonly string $fieldType,
		private readonly int $countryId,
		private readonly bool $isUF,
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
		return in_array($this->id, self::REQUIRED_FIELD_IDS, true)
			|| preg_match('/^RQ_RQ_ADDR_TYPE\|\d+$/', $this->id)
		;
	}

	public function isReadonly(): bool
	{
		return false;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		/** @see ImportHelper in ImportOperation */
		return FieldProcessResult::success();
	}

	/**
	 * @see ImportHelper::prepareEntityImportRequisiteInfo()
	 * @param array $header
	 * @return self|null
	 */
	public static function tryFromHeader(array $header): ?self
	{
		$group = RequisiteFieldType::tryFrom($header['group']);
		if ($group === null)
		{
			return null;
		}

		$isUF = ($header['isUF'] ?? null) === true;

		return new self(
			$header['id'],
			$header['name'],
			$group,
			$header['field'],
			$header['fieldType'],
			$header['countryId'],
			$isUF,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->caption,
			'group' => $this->group->value,
			'field' => $this->field,
			'fieldType' => $this->fieldType,
			'countryId' => $this->countryId,
			'isUF' => $this->isUF,
		];
	}
}
