<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Communications;

/**
 * Which way a conversation went: a call made or taken, a letter sent or received, a dialogue of an
 * open line started by us or by the client.
 *
 * `Callback` and `Unknown` are told apart on purpose: a conversation whose direction the card does not
 * carry is a callback request when the provider says so, and an ordinary one entered by hand
 * otherwise. Merging them would tell an agent that somebody was called back when nobody was. Only a
 * call is ever a callback - no other kind has a provider that asks for one.
 *
 * The values are part of the answer of the tool.
 *
 * @internal
 */
enum CommunicationDirection: string
{
	case Incoming = 'incoming';
	case Outgoing = 'outgoing';
	case Callback = 'callback';
	case Unknown = 'unknown';
}
