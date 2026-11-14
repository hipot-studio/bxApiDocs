<?php

namespace Bitrix\Mobile\Internal\Onboarding;

/**
 * Push types of both series: the activation campaign and the legacy regional day map.
 *
 * Everything a type declares about itself lives in one table, DESCRIPTORS, and the getters
 * below only read it. Adding a type is therefore a case here plus a row there - not a visit
 * to six `match` arms, which is what this used to be and what nothing reminded you to do.
 *
 * Fields of a row:
 *
 * - `action` - action type the push carries by default. Required.
 * - `analytics` - send-attempt event of the push. Required, and it always ends with
 *   `_send_attempt`: the click event of the same push is authored separately, and a send
 *   counted under a click name never arrives (bug 0243306).
 * - `configToken` - how the campaign document addresses this type in `steps[].pushType` and
 *   in the keys of `content`. Absent for the types the document never names.
 * - `finalKey` - how the `finals` section addresses it. Absent for everything but the finals.
 * - `actionMore` - action to send instead of `action` when the tab this type opens is not in
 *   the bottom menu of the user. Absent for a type that opens no tab.
 * - `campaignAction` - action of this type while a campaign document is in charge, when the
 *   campaign aims elsewhere than the ordinary target. The task and deal steps call the user
 *   to create something, so their push opens the create form rather than the list of the
 *   tool; the web step reuses the QR-auth desktop handler for the same reason. Such an
 *   action opens a screen over whatever is showing, depends on no tab and never degrades.
 *
 * The table is pinned by PushTypeTest: every case has a row, every row has `action` and
 * `analytics`, and no row declares a field the getters do not read - a misspelled key would
 * otherwise be dropped in silence.
 */
enum PushType: string
{
	case INVITE = 'invite';
	case DESKTOP = 'desktop';
	case COPILOT = 'copilot';
	case CRM = 'crm';
	case TASK = 'task';
	case CALENDAR = 'calendar';
	case RETURN = 'return';
	case DEMO_WEB = 'demo_web';
	case SET_NOTIFICATIONS = 'set_notifications';
	case PROMOTION = 'promotion';
	case MESSAGE = 'message';
	case MEETUP = 'meetup';
	case IMPLEMENTATION_HELP = 'implementation_help';

	/**
	 * Keyed by the value of the case. What each field means - in the docblock of the enum.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const DESCRIPTORS = [
		'invite' => [
			'configToken' => 'invite',
			'action' => 'MOBILE_OPEN_INVITE',
			'analytics' => 'push_mobile_1-8d_invite_send_attempt',
		],
		'desktop' => [
			'action' => 'MOBILE_OPEN_DESKTOP',
			'analytics' => 'push_mobile_1-8d_web_send_attempt',
		],
		'copilot' => [
			'action' => 'MOBILE_OPEN_COPILOT_CHAT',
			'analytics' => 'push_mobile_1-8d_copilot_send_attempt',
		],
		'crm' => [
			'configToken' => 'crm',
			'action' => 'MOBILE_OPEN_CRM_TAB',
			'actionMore' => 'MOBILE_OPEN_CRM_TAB_FROM_MORE',
			'campaignAction' => 'MOBILE_OPEN_CRM_DEAL_CREATE',
			'analytics' => 'push_mobile_1-8d_crm_send_attempt',
		],
		'task' => [
			'configToken' => 'task',
			'action' => 'MOBILE_OPEN_TASK_TAB',
			'actionMore' => 'MOBILE_OPEN_TASK_TAB_FROM_MORE',
			'campaignAction' => 'MOBILE_OPEN_TASK_CREATE',
			'analytics' => 'push_mobile_1-8d_tasks_send_attempt',
		],
		'calendar' => [
			'action' => 'MOBILE_OPEN_CALENDAR_TAB',
			'actionMore' => 'MOBILE_OPEN_CALENDAR_TAB_FROM_MORE',
			'analytics' => 'push_mobile_1-8d_calendar_send_attempt',
		],
		'return' => [
			'action' => 'MOBILE_OPEN_RETURN',
			'analytics' => 'push_mobile_1-8d_return_send_attempt',
		],
		'demo_web' => [
			'configToken' => 'web',
			'action' => 'MOBILE_OPEN_DEMO_WEB',
			'campaignAction' => 'MOBILE_OPEN_DESKTOP',
			'analytics' => 'push_mobile_1-8d_demo_web_send_attempt',
		],
		'set_notifications' => [
			'action' => 'MOBILE_OPEN_SET_NOTIFICATIONS',
			'analytics' => 'push_mobile_1-8d_set_notifications_send_attempt',
		],
		'promotion' => [
			'action' => 'MOBILE_PROMOTION',
			'analytics' => 'push_mobile_promo1_send_attempt',
		],
		'message' => [
			'configToken' => 'message',
			'action' => 'MOBILE_OPEN_CHAT_LIST',
			'analytics' => 'push_mobile_1-8d_message_send_attempt',
		],
		'meetup' => [
			'finalKey' => 'meetup',
			'action' => 'MOBILE_OPEN_MEETUP',
			'analytics' => 'push_mobile_1-8d_meetup_send_attempt',
		],
		'implementation_help' => [
			'finalKey' => 'implementation_help',
			'action' => 'MOBILE_OPEN_IMPLEMENTATION_HELP',
			'analytics' => 'push_mobile_1-8d_implementation_help_send_attempt',
		],
	];

	public static function tryFromConfigToken(string $token): ?self
	{
		foreach (self::cases() as $case)
		{
			if ($case->getConfigToken() === $token)
			{
				return $case;
			}
		}

		return null;
	}

	/**
	 * Token addressing this type in the campaign document (`steps[].pushType` and
	 * the keys of the `content` section). Null for the types of the legacy
	 * regional series, which the campaign document does not address.
	 */
	public function getConfigToken(): ?string
	{
		return $this->declared('configToken');
	}

	/**
	 * Key addressing this type in the `finals` section of the campaign document.
	 * Null for every push of the activation series and of the legacy regional series.
	 */
	public function getFinalKey(): ?string
	{
		return $this->declared('finalKey');
	}

	public function getActionType(): string
	{
		return $this->required('action');
	}

	/**
	 * Action of a push of the campaign series when the campaign aims somewhere other than the
	 * ordinary target of this push type, null when it aims at the same place.
	 */
	public function getCampaignActionType(): ?string
	{
		return $this->declared('campaignAction');
	}

	/**
	 * Every action type this push may arrive with while a campaign document is in charge: its
	 * own campaign target when it has one - such a push never degrades - and otherwise the tab
	 * action together with its More-menu variant. Empty for a push the document does not
	 * address at all: the legacy regional series sends those.
	 *
	 * The set is asked from both ends. The reader refuses a document declaring a target the
	 * code never sends, and the client side of the campaign - the click analytics of the
	 * document - has to be honoured by every handler in it.
	 *
	 * @return string[]
	 */
	public function getCampaignActionTypes(): array
	{
		if ($this->getConfigToken() === null && $this->getFinalKey() === null)
		{
			return [];
		}

		$campaignActionType = $this->getCampaignActionType();

		return $campaignActionType === null
			? array_values(array_filter([$this->getActionType(), $this->getActionMoreType()]))
			: [$campaignActionType]
		;
	}

	/**
	 * The final pushes open the More menu and highlight a banner there, so they have
	 * no tab to degrade from and declare no More-menu action.
	 */
	public function getActionMoreType(): ?string
	{
		return $this->declared('actionMore');
	}

	public function getAnalyticsEvent(): string
	{
		return $this->required('analytics');
	}

	/**
	 * Optional field of the row: absent means the type does not have this trait at all - no
	 * token in the document, no More-menu variant, no campaign target of its own.
	 */
	private function declared(string $field): ?string
	{
		return self::DESCRIPTORS[$this->value][$field] ?? null;
	}

	/**
	 * Field every type declares. A case missing from the table would reach here, and a push
	 * without an action or without a send event is not something to guess a default for -
	 * PushTypeTest keeps the table complete, and this says so out loud if it ever is not.
	 */
	private function required(string $field): string
	{
		return self::DESCRIPTORS[$this->value][$field]
			?? throw new \LogicException("Onboarding push type {$this->value} declares no {$field}");
	}
}
