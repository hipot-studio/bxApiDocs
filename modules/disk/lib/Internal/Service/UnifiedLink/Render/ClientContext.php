<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\Render;

/**
 * The client a unified link is rendered for. Internal decision: the mobile application keeps the routes
 * it had before the pages it does not host, such as the page of a Markdown file.
 */
enum ClientContext
{
	case Web;
	case Mobile;
}
