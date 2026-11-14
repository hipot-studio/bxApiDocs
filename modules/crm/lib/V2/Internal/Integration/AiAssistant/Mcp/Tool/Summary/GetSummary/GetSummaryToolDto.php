<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Summary\GetSummary;

use Bitrix\Crm\Dto\Validator\EnumField;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class GetSummaryToolDto extends AbstractToolDto
{
	/**
	 * The wire names of the entities a summary is answered for. The list is the single source of the
	 * enum of the input schema as well, so a type the tool does not answer for cannot reach the
	 * service through a schema and a validator that disagree.
	 */
	public const ENTITY_TYPES = ['contact', 'company', 'deal'];

	public ?string $entityType = null;
	public ?int $entityId = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'entityType'),
			new StringField($this, 'entityType'),
			new EnumField($this, 'entityType', self::ENTITY_TYPES),

			new RequiredField($this, 'entityId'),
			// min: 0 means "value > 0" - IntegerField compares strictly
			new IntegerField($this, 'entityId', min: 0),
		];
	}
}
