<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form;

use Bitrix\Rest\V3\Dto\Dto;

final class DependencyRuleDto extends Dto
{
	public DependencyConditionDto $condition;

	public DependencyActionDto $action;
}
