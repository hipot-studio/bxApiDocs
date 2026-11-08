<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum;

/**
 * How the answered field set was chosen. Part of the public REST contract (API-03), so the values are stable.
 *
 * The agent needs it to read its own answer: an empty `items` means "no field matches your phrase" after a
 * substring or a semantic pass, and "this type has no fields" after a full one - and a set narrowed by meaning
 * is worth re-asking with other words, while one narrowed by substring is not.
 */
enum DocumentFieldSearchMode: string
{
	/** The whole field set of the type; no phrase was given, or none was applied. */
	case All = 'all';

	/** Case-insensitive occurrence of the phrase in the code or the name of a field. */
	case Substring = 'substring';

	/** The semantic search service chose the set. */
	case Semantic = 'semantic';
}
