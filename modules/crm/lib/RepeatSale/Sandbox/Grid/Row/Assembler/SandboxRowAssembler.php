<?php

namespace Bitrix\Crm\RepeatSale\Sandbox\Grid\Row\Assembler;

use Bitrix\Crm\RepeatSale\Sandbox\Grid\Row\Assembler\Field\EntityTypeFieldAssembler;
use Bitrix\Crm\RepeatSale\Sandbox\Grid\Row\Assembler\Field\FormattedDateTimeFieldAssembler;
use Bitrix\Crm\RepeatSale\Sandbox\Grid\Row\Assembler\Field\PayloadFieldAssembler;
use Bitrix\Main\Grid\Row\RowAssembler;

final class SandboxRowAssembler extends RowAssembler
{
	protected function prepareFieldAssemblers(): array
	{
		return [
			new EntityTypeFieldAssembler([
				'ITEM_TYPE_ID',
				'CLIENT_TYPE_ID',
			]),
			new FormattedDateTimeFieldAssembler([
				'CREATED_AT',
				'UPDATED_AT',
				'CHECK_DATE',
			]),
			new PayloadFieldAssembler([
				'PAYLOAD',
			]),
		];
	}
}
