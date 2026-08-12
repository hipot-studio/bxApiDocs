<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Request;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;

/**
 * A single relation target item used by add/configure smart process relations tools.
 */
final class RelationEntityDto extends Dto
{
	public ?int $entityTypeId = null;
	public bool $isChildrenListEnabled = false;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'entityTypeId'),
			// min: 0 means "value > 0" — IntegerField uses strict comparison;
			// do NOT change to min: 1, that would reject the valid id 1.
			new IntegerField($this, 'entityTypeId', min: 0),
		];
	}
}
