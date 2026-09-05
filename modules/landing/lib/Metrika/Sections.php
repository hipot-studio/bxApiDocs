<?php
declare(strict_types=1);

namespace Bitrix\Landing\Metrika;

enum Sections: string
{
	/**
	 * GET param of an address, telling the section the user came from.
	 */
	public const URL_PARAM = 'st_section';

	case site = 'site';
	case page = 'page';
	case blockStyle = 'block_style';
	case siteEditor = 'site_editor';
	case siteDesigner = 'site_designer';
	case siteSettings = 'site_settings';
	case marketMain = 'market_main';
	case marketCollection = 'market_collection';
	case sites = 'sites';
	case chat = 'chat';
	case buttonMenu = 'button_menu';
}
