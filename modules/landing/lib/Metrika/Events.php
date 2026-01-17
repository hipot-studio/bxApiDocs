<?php
declare(strict_types=1);

namespace Bitrix\Landing\Metrika;

/**
 * Enum representing all possible Metrika events.
 */
enum Events: string
{
	case open = 'open';
	case save = 'save';
	case cancel = 'cancel';
	case start = 'start';
	case select = 'select';
	case openStartPage = 'open_start_page';
	case openMarket = 'open_market';
	case createTemplate = 'create_template';
	case createTemplateApi = 'create_template_api';
	case replaceTemplate = 'replace_template';
	case openEditor = 'open_editor';
	case publishSite = 'publish_site';
	case unpublishSite = 'unpublish_site';
	case addWidget = 'add_widget';
	case deleteWidget = 'delete_widget';
	case dataGeneration = 'data_generation';
	case textsGeneration = 'texts_generation';
	case imagesGeneration = 'images_generation';
	case addFavourite = 'add_to_favorites';
	case deleteFavourite = 'remove_from_favorites';
	case unknown = 'unknown';
}
