<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Disk;

use Bitrix\Main\ArgumentException;

/**
 * Builds the client handler of the "+" button that creates a board.
 * Linked to the disk module only by the extension name, so the module is not loaded here.
 */
final class BoardCreateAction
{
	public const MENU_ITEM_ID = 'menu_boards';
	public const ELEMENT_LEFT_MENU = 'left_menu';
	public const ELEMENT_TOP_MENU = 'top_menu';

	private const ON_CLICK_TEMPLATE = "var t = window.open('', '_blank');"
		. " BX.Runtime.loadExtension('disk.board-create').then(function(exports){"
		. " exports.createBoard({ newTab: t, analyticsElement: '#ELEMENT#' }); })"
		. ".catch(function(){ if (t) { t.close(); } });"
	;

	/**
	 * @throws ArgumentException
	 */
	public static function getOnClick(string $analyticsElement): string
	{
		if (!preg_match('/^[a-z_]+$/', $analyticsElement))
		{
			throw new ArgumentException('Analytics element must match ^[a-z_]+$', 'analyticsElement');
		}

		return str_replace('#ELEMENT#', $analyticsElement, self::ON_CLICK_TEMPLATE);
	}

	/**
	 * Adds the "+" handler to the boards item of the left menu.
	 *
	 * @param array $params Item params with lower case keys.
	 */
	public static function applyToLeftMenuParams(array $params): array
	{
		if (
			($params['menu_item_id'] ?? null) !== self::MENU_ITEM_ID
			|| !empty($params['sub_link'])
			|| !empty($params['sub_link_onclick'])
		)
		{
			return $params;
		}

		$params['sub_link_onclick'] = self::getOnClick(self::ELEMENT_LEFT_MENU);

		return $params;
	}
}
