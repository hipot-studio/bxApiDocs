<?php

namespace Bitrix\Crm\Import\ImportEntityFields;

use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\UserTypeValueMapper;
use Bitrix\Main\Type\Contract\Arrayable;
use CCrmUserType;

final class UserField implements ImportEntityFieldInterface, Arrayable
{
	public function __construct(
		private readonly CCrmUserType $userType,
		private readonly string $id,
		private readonly string $caption,
		private readonly string|bool $sort,
		private readonly bool $default,
		private readonly bool|array $editable,
		private readonly string $type,
		private readonly bool $mandatory,
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
		return $this->mandatory;
	}

	public function isReadonly(): bool
	{
		return false;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new UserTypeValueMapper($this->getId(), $this->userType))
			->process($importItemFields, $fieldBindings, $row)
		;
	}

	/**
	 * @param array $header
	 * @param CCrmUserType $userType
	 * @return self|null
	 * @see CCrmUserType::ListAddHeaders
	 */
	public static function tryFromHeader(array $header, CCrmUserType $userType): ?self
	{
		// todo: validation

		return new self(
			$userType,
			$header['id'],
			$header['name'],
			$header['sort'],
			$header['default'],
			$header['editable'],
			$header['type'],
			$header['mandatory'] === 'Y',
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
			'mandatory' => $this->mandatory ? 'Y' : 'N',
		];
	}
}
