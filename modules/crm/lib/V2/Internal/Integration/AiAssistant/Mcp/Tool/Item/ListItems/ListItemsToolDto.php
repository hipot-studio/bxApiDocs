<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\ListItems;

use Bitrix\Crm\Dto\Validator\DateTimeField;
use Bitrix\Crm\Dto\Validator\DefinedEntityTypeId;
use Bitrix\Crm\Dto\Validator\EnumField;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\ItemService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Validator\CategoryField;

final class ListItemsToolDto extends AbstractToolDto
{
	public ?int $entityTypeId = null;
	public ?string $query = null;
	public ?int $categoryId = null;
	public ?string $stageId = null;
	public ?string $stageSemantic = null;
	public ?string $closedDateFrom = null;
	public ?string $closedDateTo = null;
	public ?int $limit = null;

	protected function getValidators(array $fields): array
	{
		$validators = [
			new RequiredField($this, 'entityTypeId'),
			new IntegerField($this, 'entityTypeId'),
			new DefinedEntityTypeId($this, 'entityTypeId'),

			new CategoryField($this, 'entityTypeId', 'categoryId'),

			new StringField($this, 'query'),
			new NotEmptyField($this, 'query'),

			new StringField($this, 'stageId'),
			new NotEmptyField($this, 'stageId'),

			new EnumField($this, 'stageSemantic', [
				PhaseSemantics::PROCESS,
				PhaseSemantics::SUCCESS,
				PhaseSemantics::FAILURE,
			]),

			new DateTimeField($this, 'Y-m-d', 'closedDateFrom'),

			new DateTimeField($this, 'Y-m-d', 'closedDateTo'),

			new IntegerField($this, 'limit', min: 0, max: ItemService::DEFAULT_LIST_LIMIT + 1),
		];

		if (array_key_exists('categoryId', $fields) && $fields['categoryId'] !== null)
		{
			$validators[] = new IntegerField($this, 'categoryId');
		}

		return $validators;
	}
}
