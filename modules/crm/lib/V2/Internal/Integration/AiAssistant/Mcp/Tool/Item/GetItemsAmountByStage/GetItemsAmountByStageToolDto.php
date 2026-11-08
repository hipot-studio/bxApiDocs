<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\GetItemsAmountByStage;

use Bitrix\Crm\Dto\Validator\DateTimeField;
use Bitrix\Crm\Dto\Validator\DefinedEntityTypeId;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class GetItemsAmountByStageToolDto extends AbstractToolDto
{
	public ?int $entityTypeId = null;
	public ?string $stageId = null;
	public ?string $closedDateFrom = null;
	public ?string $closedDateTo = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'entityTypeId'),
			new IntegerField($this, 'entityTypeId'),
			new DefinedEntityTypeId($this, 'entityTypeId'),

			new RequiredField($this, 'stageId'),
			new StringField($this, 'stageId'),
			new NotEmptyField($this, 'stageId'),

			new DateTimeField($this, 'Y-m-d', 'closedDateFrom'),
			new DateTimeField($this, 'Y-m-d', 'closedDateTo'),
		];
	}
}
