<?php

namespace Bitrix\Crm\Integration\AI\Function\Category\Dto\Stage;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;

final class RenameItem extends Dto
{
	public string $stageId;
	public string $name;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'stageId'),
			new NotEmptyField($this, 'stageId'),
			new RequiredField($this, 'name'),
			new NotEmptyField($this, 'name'),
		];
	}
}
