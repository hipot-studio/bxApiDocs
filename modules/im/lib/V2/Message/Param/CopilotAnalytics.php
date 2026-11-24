<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Param;

/**
 * Labels of the mobile voice widget for the analytics of a request, see \Bitrix\Im\V2\Analytics\VoiceWidgetLabels.
 * Stored as JSON, like the page context: a string param would be cut at 100 characters.
 */
class CopilotAnalytics extends CopilotPageContext
{
}
