<?php

namespace Bitrix\Crm\Activity\Entity\ConfigurableRestApp\Dto;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\RequiredField;

final class BadgeTextDto extends Dto
{
	public ?TextWithTranslationDto $title = null;
	public ?string $color = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'title'),
			new Validator\TextWithTranslationField($this, 'title'),
		];
	}
}
