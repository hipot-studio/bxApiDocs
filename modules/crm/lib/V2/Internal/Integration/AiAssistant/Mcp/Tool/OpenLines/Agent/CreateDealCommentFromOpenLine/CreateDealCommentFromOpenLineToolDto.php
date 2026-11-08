<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\OpenLines\Agent\CreateDealCommentFromOpenLine;

use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class CreateDealCommentFromOpenLineToolDto extends AbstractToolDto
{
	public ?int $dealId = null;
	public ?string $comment = null;

	public function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'dealId'),
			new IntegerField($this, 'dealId', min: 0),

			new RequiredField($this, 'comment'),
			new StringField($this, 'comment'),
			new NotEmptyField($this, 'comment'),
		];
	}
}
