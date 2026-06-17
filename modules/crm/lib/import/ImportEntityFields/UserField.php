<?php

namespace Bitrix\Crm\Import\ImportEntityFields;

use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\UserTypeValueMapper;
use Bitrix\Main\Type\Contract\Arrayable;
use CCrmUserType;

final readonly class UserField implements ImportEntityFieldInterface, Arrayable
{
	public function __construct(
		private CCrmUserType $userType,
		private string $id,
		private string $caption,
		private string|bool $sort,
		private bool $default,
		private bool|array $editable,
		private string $type,
		private bool $mandatory,
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
		$id = $header['id'] ?? null;
		if (!is_string($id) || empty($id))
		{
			return null;
		}

		$caption = $header['name'] ?? $header['id'] ?? null;
		if (!is_string($caption) || empty($caption))
		{
			return null;
		}

		$type = $header['type'] ?? null;
		if (!is_string($type) || empty($type))
		{
			return null;
		}

		$sort = $header['sort'] ?? null;
		if (!is_bool($sort) && !is_string($sort))
		{
			$sort = false;
		}

		$editable = $header['editable'] ?? null;
		if (!is_bool($editable) && !is_array($editable))
		{
			$editable = false;
		}

		$mandatory = ($header['mandatory'] ?? null) === 'Y';
		$default = ($header['default'] ?? null) === true;

		return new self(
			$userType,
			$id,
			$caption,
			$sort,
			$default,
			$editable,
			$type,
			$mandatory,
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
