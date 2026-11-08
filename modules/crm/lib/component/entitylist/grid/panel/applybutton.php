<?php

namespace Bitrix\Crm\Component\EntityList\Grid\Panel;

use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\Grid\Panel\Snippet;

final class ApplyButton
{
	/**
	 * @param string $gridId address of the grid the panel answers for, must not be empty
	 * @param array $params params of \Bitrix\Main\Grid\Panel\Snippet::getApplyButton()
	 * @return array apply button whose id carries the grid address: a card shows several panels at once
	 * @throws ArgumentNullException when the grid address is empty and the id would stay page wide
	 */
	public static function forGrid(string $gridId, array $params): array
	{
		if ($gridId === '')
		{
			throw new ArgumentNullException('gridId');
		}

		$button = (new Snippet())->getApplyButton($params);

		$button['ID'] .= '_' . $gridId;

		return $button;
	}
}
