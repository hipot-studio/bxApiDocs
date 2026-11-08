<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

use Bitrix\Mail\Helper\AnalyticsHelper;

/**
 * Where a row opens a message, as one template for the whole screen.
 *
 * The address depends on the `SEF_FOLDER` and `SEF_URL_TEMPLATES` the complex component is included
 * with (mail/install/components/bitrix/mail.client/class.php:75), which only the page of the mail
 * section knows. So the screen sends the template once and every page carries message ids alone: the
 * controller has no access to those parameters and would have to guess them.
 */
final class MessageHrefTemplate
{
	/**
	 * `#id#` cannot stand here: source analytics is appended by a URL parser, and to it everything
	 * behind a `#` is a fragment. The client knows the same word (`message-href.ts`).
	 */
	public const ID_PLACEHOLDER = '__MESSAGE_ID__';

	/**
	 * @param string $messageViewPath `msg_view` of the complex component as the page resolved it
	 *                                (`PATH_TO_MAIL_MSG_VIEW`).
	 */
	public static function fromMessageViewPath(string $messageViewPath): string
	{
		return AnalyticsHelper::addSourceAnalyticsToMessage(
			\CComponentEngine::makePathFromTemplate($messageViewPath, ['id' => self::ID_PLACEHOLDER]),
			AnalyticsHelper::ENTITY_TYPE_MAIL,
		);
	}
}
