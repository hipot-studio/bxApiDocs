<?php

namespace Bitrix\Crm\Activity\Entity\ConfigurableRestApp\Dto;

use Bitrix\Crm\Activity\Entity\ConfigurableRestApp\Dto\Validator\TextWithTranslationField;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\EnumField;
use Bitrix\Crm\Dto\Validator\RequiredField;

final class MenuSectionDto extends Dto
{
	public ?string $code = null;
	public ?TextWithTranslationDto $title = null;
	public ?string $design = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'code'),
			new TextWithTranslationField($this, 'title'),
			new EnumField($this, 'design', ['default', 'accent']),
		];
	}
}
