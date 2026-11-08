<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\MyCompany\Dto;

use Bitrix\Mobile\Dto\Dto;

/**
 * Data of the support card: the two destinations it can open. Nothing here is needed to draw the
 * card - its icon and phrase are static - so the whole payload is asked for the tap alone.
 */
final class SupportCardData extends Dto
{
	/** Id of the support bot, or 0 when the portal has none. */
	public int $supportBotId;

	/** Helpdesk address of the current language, or null without the ui module. */
	public ?string $helpdeskUrl;

	/**
	 * Which of the two destinations the tap opens: the bot when the portal has one, the helpdesk
	 * otherwise. The same rule the menu answer carries as `restrictions.canUseSupport`.
	 */
	public bool $canUseSupport;
}
