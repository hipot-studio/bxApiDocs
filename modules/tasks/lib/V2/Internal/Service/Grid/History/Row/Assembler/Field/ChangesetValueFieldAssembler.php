<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Main\Web\Json;

class ChangesetValueFieldAssembler extends FieldAssembler
{
	/**
	 * @param array $value
	 * @return string
	 */
	protected function prepareColumn($value): string
	{
		$value = array_map(fn ($changeset) => $changeset instanceof Arrayable ? $changeset->toArray() : $changeset, $value);
		$value = array_map(fn ($changeset) => Json::encode($changeset), $value);
		$value = array_map(fn ($changeset) => htmlspecialcharsbx($changeset), $value);

		$fromValue = (string)($value['fromValue'] ?? '');
		$toValue = (string)($value['toValue'] ?? '');

		return <<<HTML
<div data-changeset-from-value="$fromValue" data-changeset-to-value="$toValue"></div>
HTML;
	}
}
