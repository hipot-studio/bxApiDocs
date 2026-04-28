<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Requisite\Dictionary;

enum RatioPart: string
{
	case SiteId = 'siteId';
	case SiteType = 'type';
	case AppCode = 'appCode';
	case AdditionalFieldsSite = 'additionalFieldsSite';
	case AdditionalFieldsSiteBefore = 'additionalFieldsSiteBefore';
	case Landings = 'landings';
	case LandingsBefore = 'landingsBefore';
	case Blocks = 'blocks';
	case BlocksPending = 'blocksPending';
	case SpecialPages = 'specialPages';
	case SysPages = 'sysPages';
	case IndexPageId = 'indexPageId';
	case Templates = 'templates';
	case TemplateLinking = 'templateLinking';
	case TemplateLinkingBefore = 'templateLinkingBefore';
	case FolderReferences = 'folderReferences';
	case FoldersNew = 'foldersNew';
}
