<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AI\Function\Category\Dto\Stage;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\Dto\Validator\StringStartsWith;

final class UpdateListStage extends Dto
{
	public string $name;
	public ?string $color = null;

	protected function getValidators(array $fields): array
	{
		return [
			new StringField($this, 'name'),
			new NotEmptyField($this, 'name'),

			new StringField($this, 'color'),
			new StringStartsWith($this, 'color', '#'),
		];
	}
}
