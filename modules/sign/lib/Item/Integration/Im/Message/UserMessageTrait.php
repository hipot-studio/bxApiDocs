<?php

namespace Bitrix\Sign\Item\Integration\Im\Message;

use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\Im\MessageTextSanitizer;

/**
 * Carries the text written by the document sender for messages that support it.
 *
 * The text is kept as it was entered: every consumer prepares its own safe representation,
 * so the same source value reaches the message card and the message body.
 */
trait UserMessageTrait
{
	protected ?string $userMessage = null;

	public function getUserMessage(): ?string
	{
		return $this->userMessage;
	}

	/**
	 * The user text goes into a separate block and never becomes a part of the localized phrase.
	 *
	 * The block title is taken in the language of the recipient, so it may be missing while the
	 * translations are on their way. The text itself is delivered anyway, without a title: the same
	 * way the web card renders it (im/install/js/im/v2/component/message/sign/src/sign.js hides the
	 * header when its phrase is absent). Losing the text here would take it out of the invitation
	 * body, the push and the letter, while the card would keep showing it.
	 */
	protected function appendUserMessageBlock(string $fallbackText): string
	{
		if ($this->userMessage === null || $this->userMessage === '')
		{
			return $fallbackText;
		}

		$safeText = $this->getMessageTextSanitizer()->toSafeBBCode($this->userMessage);
		$block = $this->getLocalizedFallbackMessage(
			'SIGN_CALLBACK_CHAT_INVITE_USER_MESSAGE',
			[
				'#USER_MESSAGE#' => $safeText,
			]
		);

		return $fallbackText . '[br][br]' . ($block ?? $safeText);
	}

	protected function getMessageTextSanitizer(): MessageTextSanitizer
	{
		return Container::instance()->getMessageTextSanitizer();
	}
}
