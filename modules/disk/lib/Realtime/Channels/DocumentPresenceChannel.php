<?php
declare(strict_types=1);

namespace Bitrix\Disk\Realtime\Channels;

/**
 * The channel the openings of one presence scope confirm themselves to each other in. It is not the
 * public channel of the object: that one is listened to by every opening of the editor, an opening by an
 * external link among them, while a confirmation names a user of the portal. This one is keyed by the
 * scope of the presence, so its listeners are exactly the openings the roster of that scope is served to.
 */
final class DocumentPresenceChannel extends Channel
{
	public function __construct(private readonly string $scopeKey)
	{
	}

	public function getName(): string
	{
		return "document_presence_{$this->scopeKey}";
	}
}
