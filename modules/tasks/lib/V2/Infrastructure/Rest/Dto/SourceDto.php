<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Internal\Entity\Task\Source;

class SourceDto extends Dto
{
	public ?string $type = null;
	public ?array $data = null;

	public static function fromEntity(?Source $source, ?Request $request = null): ?self
	{
		if (!$source)
		{
			return null;
		}
		$select = $request?->select?->getList(true) ?? [];
		$dto = new self();
		if (empty($select) || in_array('type', $select, true))
		{
			$dto->type = $source->type;
		}
		if (empty($select) || in_array('data', $select, true))
		{
			$dto->data = $source->data;
		}

		return $dto;
	}
}
