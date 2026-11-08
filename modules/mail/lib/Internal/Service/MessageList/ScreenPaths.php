<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

/**
 * The PATH_TO_USER_* parameters the complex component is included with, as the screen resolved them
 * for its page (mail/install/components/bitrix/mail.client/class.php:42). The user placeholder is
 * already substituted; the rest is left to RowHrefTemplates.
 *
 * Read by the screen alone. A page built away from it carries identifiers and parameters instead,
 * because these settings never reach the controller - see RowHrefTemplates, and MessageHrefTemplate
 * for the address of the message itself.
 */
final class ScreenPaths
{
	public function __construct(
		public readonly string $task,
		public readonly string $blogPostEdit,
		public readonly string $blogPost,
		public readonly string $calendarEvent,
	)
	{
	}
}
