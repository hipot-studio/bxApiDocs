<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Scrum\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Socialnetwork\V2\Internal\Entity\File;

class ScrumNameFieldAssembler extends FieldAssembler
{
	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$row['columns'] ??= [];

		$data = $row['data'];
		$name = $data['NAME'] ?? '';
		/** @var File|null $image */
		$image = $data['IMAGE'] ?? null;
		$viewUrl = (string)($data['VIEW_URL'] ?? '');

		$escapedName = htmlspecialcharsbx($name);
		$picPath = htmlspecialcharsbx($image?->src ?? '');
		$nameNode = $viewUrl === ''
			? '<span>' . $escapedName . '</span>'
			: '<a href="' . htmlspecialcharsbx($viewUrl) . '" class="socialnetwork-project-list-name-link">'
				. $escapedName
				. '</a>';

		$html = '<div class="socialnetwork-project-list-name-container">'
			. '<span class="socialnetwork-project-list-scrum-avatar"'
			. ' data-title="' . $escapedName . '"'
			. ' data-pic="' . $picPath . '"'
			. '></span>'
			. $nameNode
			. '</div>';

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $html;
		}

		return $row;
	}
}
