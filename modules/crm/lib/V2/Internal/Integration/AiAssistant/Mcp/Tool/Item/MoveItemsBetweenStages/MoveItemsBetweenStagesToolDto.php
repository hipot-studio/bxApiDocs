<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\MoveItemsBetweenStages;

use Bitrix\Crm\Dto\Validator\DefinedEntityTypeId;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class MoveItemsBetweenStagesToolDto extends AbstractToolDto
{
	public ?int $entityTypeId = null;
	public ?string $sourceStageId = null;
	public ?string $targetStageId = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'entityTypeId'),
			new IntegerField($this, 'entityTypeId'),
			new DefinedEntityTypeId($this, 'entityTypeId'),

			new RequiredField($this, 'sourceStageId'),
			new StringField($this, 'sourceStageId'),
			new NotEmptyField($this, 'sourceStageId'),

			new RequiredField($this, 'targetStageId'),
			new StringField($this, 'targetStageId'),
			new NotEmptyField($this, 'targetStageId'),
		];
	}
}
