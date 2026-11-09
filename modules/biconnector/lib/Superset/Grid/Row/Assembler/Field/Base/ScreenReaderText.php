<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base;

use Bitrix\Main\Localization\Loc;

final class ScreenReaderText
{
	private const CSS_CLASS = 'biconnector-grid-sr-only';

	public static function render(string $escapedText, ?string $id = null): string
	{
		$idAttribute = $id === null ? '' : ' id="' . htmlspecialcharsbx($id) . '"';

		return '<span class="' . self::CSS_CLASS . '"' . $idAttribute . '>' . $escapedText . '</span>';
	}

	/**
	 * @param string[] $escapedItems
	 */
	public static function renderRest(array $escapedItems): string
	{
		if (empty($escapedItems))
		{
			return '';
		}

		$template = htmlspecialcharsbx((string)Loc::getMessage('BICONNECTOR_GRID_HIDDEN_REST_ARIA'));

		return self::render(str_replace('#LIST#', implode(', ', $escapedItems), $template));
	}
}
