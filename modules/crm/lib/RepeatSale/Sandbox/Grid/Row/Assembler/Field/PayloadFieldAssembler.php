<?php

namespace Bitrix\Crm\RepeatSale\Sandbox\Grid\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Web\Json;

final class PayloadFieldAssembler extends FieldAssembler
{
	protected function prepareColumn($value): string
	{
		return htmlspecialcharsbx(Json::encode($value));
	}
}
