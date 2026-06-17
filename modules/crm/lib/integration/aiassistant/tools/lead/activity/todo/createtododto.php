<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Lead\Activity\ToDo;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\DateTimeField;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Main\Type\DateTime;

final class CreateToDoDto extends Dto
{
	public ?int $leadId = null;
	public ?string $title = null;
	public ?string $description = null;
	public ?DateTime $deadline = null;

	public function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'leadId'),
			new IntegerField($this, 'leadId', min: 0),

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
