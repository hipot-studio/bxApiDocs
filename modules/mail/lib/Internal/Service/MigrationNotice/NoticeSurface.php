<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MigrationNotice;

/** The surface requesting a consent panel or a general announcement. */
enum NoticeSurface: string
{
	/**
	 * A page of the portal outside mail. It is answered with nothing, and that is the rule and not an
	 * omission: the name is kept so that a client still asking under it is told there is nothing to
	 * show, instead of being refused as if it had invented a surface.
	 */
	case Portal = 'portal';

	case Mail = 'mail';
	case MailboxSettings = 'mailbox_settings';
	case Crm = 'crm';
	case MobilePortal = 'mobile_portal';
	case MobileList = 'mobile_list';

	/**
	 * Whether the announcement may be issued for this surface. It is met where it is about something -
	 * on the way into mail: the page of the Mail section, and the entry of the application.
	 *
	 * A page of the portal is not one of them. The announcement is news about mail, and a person
	 * reading a chat or a task is not asking about mail: a message over the whole product interrupts
	 * everybody everywhere to say something only the Mail section can act on. It waits there instead.
	 *
	 * The CRM card shows the letter in a slider of its own, and a message positioned against the page
	 * would be a box inside that frame. The message list of the application draws no announcement
	 * either - the application asks for it on its way in, before the list is mounted - so a decision
	 * handed to the list would be dropped without a word, and the announcement due once for a lifetime
	 * would be spent on nobody.
	 */
	public function carriesAnnouncement(): bool
	{
		return $this === self::Mail || $this === self::MobilePortal;
	}
}
