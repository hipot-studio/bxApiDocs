<?php

namespace Bitrix\Bizproc\Api\Enum\Template;

/**
 * Entry point a template change came through: editor autosave, an AI tool, an external REST agent
 * or a path without user context. Used to resolve the author type of a change record.
 */
enum TemplateChangeRequestSource: string
{
	case Editor = 'editor';
	case Marta = 'marta';
	case ExternalAgent = 'external_agent';
	case System = 'system';
}
