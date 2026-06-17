<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\StorageList\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;

class TitleFieldAssembler extends FieldAssembler
{
	protected function prepareRow(array $row): array
	{
		$row['columns'] ??= [];

		$id = (int)($row['data']['ID'] ?? 0);
		$title = htmlspecialcharsbx($row['data']['TITLE'] ?? '');
		$url = '/bitrix/components/bitrix/bizproc.storage.item.list/?storageId=' . $id;

		$row['columns']['TITLE'] = '<a href="' . htmlspecialcharsbx($url) . '">' . $title . '</a>';

		return $row;
	}
}
