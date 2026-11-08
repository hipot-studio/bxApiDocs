<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Request\PageLayout;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;

final class SectionElement extends Dto
{
	public ?string $id = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'id'),
			new StringField($this, 'id'),
			new NotEmptyField($this, 'id'),
		];
	}
}
