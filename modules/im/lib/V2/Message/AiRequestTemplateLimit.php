<?php

namespace Bitrix\Im\V2\Message;

use Bitrix\Im\V2\Message;

/**
 * Length limits of the fields of a personal record of the scenario library.
 *
 * Both surfaces read the numbers from here: the web extension takes them in its config, the mobile
 * one through the feature set of the application. The limit of the instruction is the limit of a
 * single message of the messenger - a record is launched as a message, so a longer one has nowhere
 * to go; the limit of the title has no other owner and is declared here.
 */
final class AiRequestTemplateLimit
{
	public const TITLE_LENGTH = 255;

	/**
	 * @return array{titleLength: int, instructionLength: int}
	 */
	public static function getClientLimits(): array
	{
		return [
			'titleLength' => self::TITLE_LENGTH,
			'instructionLength' => Message::MESSAGE_MAX_LENGTH,
		];
	}
}
