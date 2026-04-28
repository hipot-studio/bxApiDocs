<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Requisite\Dictionary;

enum AdditionalOptionPart: string
{
	case SiteId = 'siteId';
	case ReplacePageId = 'replaceLid';
	case ReplaceSiteId = 'replaceSiteId';
	case Title = 'title';
	case Description = 'description';
	case AppCode = 'appCode';
	case FolderId = 'folderId';
	case MetrikaCategory = 'st_category';
	case MetrikaEvent = 'st_event';
	case MetrikaSection = 'st_section';
}
