<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Timeline\CreateToDo;

use Bitrix\Crm\Dto\Validator\DateTimeField;
use Bitrix\Crm\Dto\Validator\DefinedEntityTypeId;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use Bitrix\Main\Type\DateTime;

final class CreateToDoToolDto extends AbstractToolDto
{
	public ?int $entityTypeId = null;
	public ?int $itemId = null;
	public ?string $title = null;
	public ?string $description = null;
	public ?DateTime $deadline = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'entityTypeId'),
			new IntegerField($this, 'entityTypeId'),
			new DefinedEntityTypeId($this, 'entityTypeId'),

			new RequiredField($this, 'itemId'),
			new IntegerField($this, 'itemId', min: 0),

			new RequiredField($this, 'title'),
			new StringField($this, 'title'),
			new NotEmptyField($this, 'title'),

			new RequiredField($this, 'description'),
			new StringField($this, 'description'),
			new NotEmptyField($this, 'description'),

			new RequiredField($this, 'deadline'),
			new DateTimeField($this, DateTime::getFormat(), 'deadline'),
		];
	}
}
