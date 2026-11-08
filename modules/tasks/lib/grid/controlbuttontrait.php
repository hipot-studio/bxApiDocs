<?php

namespace Bitrix\Tasks\Grid;

trait ControlButtonTrait
{
	public function renderControlButton(
		array $attributes,
		string $innerHtml,
		string $onclick = '',
		string $onclickQuote = '"'
	): string
	{
		$attributes['type'] = 'button';
		$attributes['class'] = trim(($attributes['class'] ?? '') . ' tasks-grid-control');

		$html = '<button';
		foreach ($attributes as $key => $value)
		{
			if ($value === '' && $key !== 'class' && $key !== 'type')
			{
				continue;
			}
			$html .= ' ' . $key . '="' . htmlspecialcharsbx($value) . '"';
		}

		if ($onclick !== '')
		{
			$html .= ' onclick=' . $onclickQuote . $onclick . $onclickQuote;
		}

		return $html . '>' . $innerHtml . '</button>';
	}
}
