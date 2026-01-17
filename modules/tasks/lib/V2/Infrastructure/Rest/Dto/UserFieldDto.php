<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Internal\Entity\UserField;

class UserFieldDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;
	public ?string $key;
	public mixed $value;

	public static function fromEntity(?UserField $userField, ?Request $request = null): ?self
	{
		if (!$userField)
		{
			return null;
		}
		$select = $request?->select?->getList(true) ?? [];
		$dto = new self();
		if (empty($select) || in_array('key', $select, true))
		{
			$dto->key = $userField->key;
		}
		if (empty($select) || in_array('value', $select, true))
		{
			$dto->value = $userField->value;
		}

		return $dto;
	}
}
