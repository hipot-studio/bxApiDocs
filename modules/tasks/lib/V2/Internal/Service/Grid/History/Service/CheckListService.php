<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Service;

class CheckListService
{
	public function fillCheckListItem(string $title, bool $isChecked = false): array
	{
		return [
			'title' => $title,
			'isChecked' => $isChecked,
		];
	}
}
