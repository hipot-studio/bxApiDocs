<?php

namespace Bitrix\Disk\Document\Models;

enum DocumentService: string
{

	case OnlyOffice = 'onlyoffice';
	case FlipChart = 'flipchart';
	case Vibeoffice = 'vibeoffice';
	case HtmlEditor = 'html';
	case MarkdownEditor = 'markdown';

}
