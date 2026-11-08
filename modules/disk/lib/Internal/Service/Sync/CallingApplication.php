<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync;

/**
 * The name of the caller the contour works for, as the portal knows it.
 *
 * Everything the contour keeps is kept for a pair of a user and an application - the fetch, the
 * position of the journal, the snapshot of the codes - so the application has to be nameable. An
 * application authorised through OAuth is named by its client identifier; a webhook has none and is
 * named by the identifier of its key, prefixed so that the two kinds of name cannot collide.
 *
 * The name is taken from the credentials the request was authorised by and never from anything the
 * request carries, which is what makes it a name and not a claim. It is the same on both ends of a
 * link handed out by the contour: a link is minted with the credentials of the caller that asked
 * for it and is therefore presented by that same caller.
 *
 * A caller the portal cannot name at all - an authorisation that names no application, such as the
 * session of the portal itself - has no name here, and every part of the contour refuses it rather
 * than putting it into one shared bucket where any caller could spend what belongs to another.
 */
final class CallingApplication
{
	private const WEBHOOK_NAME_PREFIX = 'webhook.';

	public static function nameOf(?\CRestServer $server): ?string
	{
		$clientId = (string)$server?->getClientId();
		if ($clientId !== '')
		{
			return $clientId;
		}

		$passwordId = (string)$server?->getPasswordId();
		if ($passwordId !== '')
		{
			return self::WEBHOOK_NAME_PREFIX . $passwordId;
		}

		return null;
	}
}
