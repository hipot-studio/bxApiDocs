<?php

declare(strict_types=1);

namespace Bitrix\Call\DTO\FollowUp\Enum;

/**
 * How @-mentions are rendered inside AI text fields of FollowUpDto.
 *
 *  - Bb   — BBCode markers (e.g. `[USER=42]Name[/USER]`). Default; compatible with mobile and legacy AJAX.
 *  - Html — anchor links (`<a href="...">Name</a>`); for web UI rendering.
 *  - None — plain text with markup stripped; for LLM/agent consumers.
 *
 * Used as the type of GetFollowUpRequest::$mentionFormat / ListFollowUpRequest::$mentionFormat.
 * V3 framework binds incoming string → enum via tryFrom(), and rejects any other value
 * with InvalidRequestFieldTypeException (HTTP 400).
 */
enum MentionFormat: string
{
	case Bb = 'bb';
	case Html = 'html';
	case None = 'none';
}
