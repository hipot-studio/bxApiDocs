<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\ImOpenLines\EventHandler;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\BlocksBuilder\Degradation\FooterDegradationService;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Main\Event;

/**
 * An external channel of an open line carries text, so the footer of a block does not reach it. What
 * is kept as text and what goes without a trace is decided here, by the messenger - the open line
 * only asks before it hands the text to the channel.
 * @see FooterDegradationService
 */
final class FooterLinkHandler
{
	/** The open line is about to send the message to the channel for the first time. */
	private const OPERATION_SEND = 'send';

	public static function onBeforeMessageDeliver(Event $event): void
	{
		$messageId = (int)$event->getParameter('MESSAGE_ID');
		if ($messageId <= 0 || !self::canCarryBlocks($event))
		{
			return;
		}

		$text = (string)$event->getParameter('MESSAGE');
		$textWithLinks = (new FooterDegradationService())
			->appendLinkUrlsToText($text, (new Message($messageId))->getBlocksBuilder())
		;

		if ($textWithLinks !== $text)
		{
			$event->setParameter('MESSAGE', $textWithLinks);
		}
	}

	/**
	 * Reading the blocks costs a load of the message and a load of its params, so a message that surely
	 * has none is not read back from the base.
	 *
	 * The param carrying the blocks is hidden and reaches the params of a send as a name without a
	 * value, so only the name tells. An edit carries the params through the legacy reader, which drops
	 * every name it does not know, the blocks among them - that way out has no signal of its own.
	 * @see \CIMMessageParam::PrepareValues()
	 */
	private static function canCarryBlocks(Event $event): bool
	{
		if ((string)$event->getParameter('OPERATION') !== self::OPERATION_SEND)
		{
			return true;
		}

		$params = $event->getParameter('PARAMS');

		return is_array($params) && array_key_exists(Params::BLOCK, $params);
	}
}
