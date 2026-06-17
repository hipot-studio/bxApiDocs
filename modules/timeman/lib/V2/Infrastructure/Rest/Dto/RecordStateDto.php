<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Timeman\V2\Internal\Entity\Record\RecordState;

class RecordStateDto extends Dto
{
	public ?string $status;

	public static function fromEntity(?RecordState $state): ?self
	{
		if ($state === null)
		{
			return null;
		}

		$dto = new self();
		$dto->status = $state->status->value;

		return $dto;
	}
}
