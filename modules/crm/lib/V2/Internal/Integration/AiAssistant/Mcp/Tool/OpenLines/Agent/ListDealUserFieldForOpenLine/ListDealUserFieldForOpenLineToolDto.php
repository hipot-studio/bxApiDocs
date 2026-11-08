<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\OpenLines\Agent\ListDealUserFieldForOpenLine;

use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ListDealUserFieldForOpenLineToolDto extends AbstractToolDto
{
	public ?int $dealId = null;

	public function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'dealId'),
			new IntegerField($this, 'dealId', min: 0),
		];
	}
}
