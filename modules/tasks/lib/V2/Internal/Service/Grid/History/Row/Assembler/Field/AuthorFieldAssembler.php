<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Web\Json;

class AuthorFieldAssembler extends FieldAssembler
{
	/**
	 * @param array $value
	 * @return string
	 */
	protected function prepareColumn($value): string
	{
		$value = array_map(fn ($changeset) => Json::encode($changeset), $value);
		$value = array_map(fn ($changeset) => htmlspecialcharsbx($changeset), $value);

		$authorId = (string)($value['id'] ?? '');
		$authorName = (string)($value['name'] ?? '');
		$authorType = (string)($value['type'] ?? '');

		return <<<HTML
<div data-author="$authorName" data-author-id="$authorId" data-author-type="$authorType"></div>
HTML;
	}
}
