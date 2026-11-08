<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MigrationNotice;

/**
 * Where the notice is about to be shown (API-01).
 *
 * Every message of the feature lives in mail: the Mail section, the CRM card and the message list of
 * the application. The announcement is met on the way into mail, and on the web that is the Mail
 * section itself; in the application it is the entry the list is mounted behind.
 *
 * The application names surfaces of its own and never borrows the ones of the web. It draws its
 * messages with screens of its own, and what may be offered on a screen is not always what may be
 * offered on a page ({@see self::isOfTheApplication()}); a client that asked as the web would be
 * given a decision built for the web, and the difference would have to be undone on the client -
 * which is the one place no rule of this feature is allowed to live.
 */
enum NoticeSurface: string
{
	/**
	 * A page of the portal outside mail. It is answered with nothing, and that is the rule and not an
	 * omission: the name is kept so that a client still asking under it is told there is nothing to
	 * show, instead of being refused as if it had invented a surface.
	 */
	case Portal = 'portal';

	case Mail = 'mail';
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

	/**
	 * Whether a message about a mailbox may be shown here at all. The two surfaces that stand for a
	 * whole product carry the announcement and nothing besides: neither knows a mailbox, and neither
	 * has a place of its own to put a message about one in.
	 */
	public function carriesMigrationMessages(): bool
	{
		return $this !== self::Portal && $this !== self::MobilePortal;
	}

	/**
	 * Whether the mobile application draws this surface rather than the web.
	 *
	 * The two clients are not offered the same ways out. A button of a decision reaches the
	 * application as a screen of its own whenever the application knows what the button means, and a
	 * way that has no such screen there is a way the person cannot finish - so which ways a message
	 * offers is decided per surface ({@see NoticeDecisionService}).
	 */
	public function isOfTheApplication(): bool
	{
		return $this === self::MobilePortal || $this === self::MobileList;
	}

	/**
	 * Whether the offer of a mailbox on Bitrix24 Mail may be put beside the alert of a broken mailbox
	 * here (AC-031). Nowhere, until the product says otherwise.
	 *
	 * The offer is buttons and nothing else: the alert beside it is drawn by somebody else, with words of
	 * its own. Its one button leads into the control panel of the company mail, and so do the buttons of
	 * the migration messages - but those the person meets while their mail still works, while this one
	 * stands beside an alert that already says the mailbox is broken and is met by whoever owns such a
	 * mailbox, administrator or not. Entering the panel is an administrator's right.
	 *
	 * Held back by decision of 2026-09-30 rather than deleted: the code, the wording and the way the
	 * clients draw it all stand, so answering the question about rights brings the offer back by this one
	 * line.
	 */
	public function carriesBrokenMailboxOffer(): bool
	{
		return false;
	}
}
