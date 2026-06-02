<?php

namespace Bitrix\Crm\Activity\Entity\ConfigurableRestApp\Dto\Validator;

use Bitrix\Crm\Dto\Validator\EnumField;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Service\Timeline\Layout\Menu\MenuItemDesign;

final class DesignField extends EnumField
{
	public function __construct(Dto $dto, string $fieldToCheck)
	{
		parent::__construct(
			$dto,
			$fieldToCheck,
			array_column(MenuItemDesign::cases(), 'value'),
		);
	}
}
