<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Request\Stage;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\EnumField;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Validator\HexColorField;

final class CreateStageDto extends Dto
{
	public ?string $title = null;
	public ?string $color = null;
	public ?string $semantics = null;
	public ?int $sort = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'title'),
			new StringField($this, 'title'),
			new NotEmptyField($this, 'title'),

			new StringField($this, 'color'),
			new HexColorField($this, 'color'),

			new RequiredField($this, 'semantics'),
			new EnumField($this, 'semantics', [
				PhaseSemantics::PROCESS,
				PhaseSemantics::FAILURE,
			]),

			new RequiredField($this, 'sort'),
			new IntegerField($this, 'sort', min: 0),
		];
	}
}
