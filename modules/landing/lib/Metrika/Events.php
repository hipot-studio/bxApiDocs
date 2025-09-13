<?php
declare(strict_types=1);

namespace Bitrix\Landing\Metrika;

enum Events: string
{
	case open = 'open';
	case save = 'save';
	case cancel = 'cancel';
	case start = 'start';
	case openStartPage = 'open_start_page';
	case openSettingsMain = 'open_settings_main';
	case openMarket = 'open_market';
	case previewTemplate = 'preview_template';
	case createTemplate = 'create_template';
	case replaceTemplate = 'replace_template';
	case openEditor = 'open_editor';
	case publishSite = 'publish_site';
	case unpublishSite = 'unpublish_site';
	case addWidget = 'add_widget';
	case deleteWidget = 'delete_widget';
	case clickOnButton = 'click_on_button';
}
