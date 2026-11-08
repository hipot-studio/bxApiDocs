<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\MyCompany\Dto;

use Bitrix\Mobile\Dto\Dto;

/**
 * Data of the "Bitrix24 Answers" card: the page the screen opens and the two destinations support is
 * opened by from inside that screen. Support travels here because it is asked for on a tap made when
 * the screen is already up, and a request of its own at that moment would buy nothing over three
 * fields of this answer.
 */
final class BitrixAnswersCardData extends Dto
{
	/** Address of the page the screen opens. Never empty: no address means no card at all. */
	public string $url;

	/** Id of the support bot, or 0 when the portal has none. */
	public int $supportBotId;

	/** Helpdesk address of the fallback path of support, or null without the ui module. */
	public ?string $helpdeskUrl;

	/**
	 * Which of the two paths opens support: the bot when the portal has one, the helpdesk otherwise.
	 * The same rule the support card carries.
	 */
	public bool $canUseSupport;
}
