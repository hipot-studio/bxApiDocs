<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\OpenLines\Agent\CreateLeadCommentFromOpenLine;

use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class CreateLeadCommentFromOpenLineToolDto extends AbstractToolDto
{
	public ?int $leadId = null;
	public ?string $comment = null;

	public function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'leadId'),
			new IntegerField($this, 'leadId', min: 0),
			new RequiredField($this, 'comment'),
			new StringField($this, 'comment'),
			new NotEmptyField($this, 'comment'),
		];
	}
}
