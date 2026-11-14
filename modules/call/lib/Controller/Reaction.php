<?php

declare(strict_types=1);

namespace Bitrix\Call\Controller;

use Bitrix\Call\Analytics\ReactionAnalytics;
use Bitrix\Call\CallFactory;
use Bitrix\Call\Controller\ActionFilter\DenyGuest;
use Bitrix\Call\Controller\ActionFilter\SameOrigin;
use Bitrix\Call\Model\CallUserTable;
use Bitrix\Call\Promotion\ReactionsOnboardingPromo;
use Bitrix\Call\Reaction\QuickListProvider;
use Bitrix\Call\Reaction\ReactionCatalog;
use Bitrix\Call\Reaction\ReactionThrottle;
use Bitrix\Call\Reaction\ReactionUsageStore;
use Bitrix\Call\Service\CallLogService;
use Bitrix\Call\Settings;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\EnablePrefilters;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\HttpMethod;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\Scope;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Request;

/**
 * Personalization of the reaction picker.
 *
 * The whole `\Bitrix\Call\Controller` namespace is published to REST (`call/.settings.php`), so every
 * action keeps itself out of that scope explicitly - an internal helper of a picker has no business
 * being part of the public API.
 *
 * Do not drop the Scope rule to make a client happy. It closes the path the mobile app normally takes
 * to call.* (BX.rest.callMethod), and the mobile picker therefore reaches these actions over ajax
 * instead. Opening them to REST would turn three internal calls into a public api of the module, and
 * the security review of the feature rests on them being unreachable that way.
 *
 * @internal
 */
class Reaction extends Controller
{
	private const RESPONSE_OK = ['status' => 'ok'];

	private ReactionUsageStore $usageStore;
	private ReactionThrottle $throttle;
	private QuickListProvider $quickListProvider;

	public function __construct(
		?Request $request = null,
		?ReactionThrottle $throttle = null,
		?ReactionUsageStore $usageStore = null,
	)
	{
		parent::__construct($request);

		$this->usageStore = $usageStore ?? new ReactionUsageStore();
		$this->throttle = $throttle ?? new ReactionThrottle();
		$this->quickListProvider = new QuickListProvider($this->usageStore);
	}

	/**
	 * Counts one use of a reaction for the current user.
	 *
	 * The client fires this off and moves on - it has already drawn the reaction and never waits for
	 * an answer - so nothing here is worth an error: a disabled feature, an id the picker does not
	 * show and a guest are all answered with the same silent ok.
	 *
	 * Every use lands in the quick row history, since the client has already lifted it in its row. Only
	 * analytics is rate-limited.
	 *
	 * @param string $callUuid Call the reaction was sent in. Only analytics needs it - the counter is
	 *                         per user and does not depend on a call.
	 * @param string $source Where the reaction was picked from, see ReactionEvent::SOURCE_*. Only
	 *                       analytics needs it; an unknown value costs the event that field, nothing more.
	 */
	#[Scope(ActionFilter\Scope::NOT_REST)]
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	#[EnablePrefilters([new SameOrigin()])]
	public function useAction(string $reactionId, string $callUuid = '', string $source = ''): array
	{
		if (!Settings::isCallReactionsEnabled())
		{
			return self::RESPONSE_OK;
		}

		$userId = (int)($this->getCurrentUser()?->getId() ?? 0);
		if ($userId < 1)
		{
			return self::RESPONSE_OK;
		}

		if (!ReactionCatalog::isVisible($reactionId))
		{
			return self::RESPONSE_OK;
		}

		// A call guest is a real user, so the id check above lets them through. Checked before the
		// claim: a guest takes no rate-limit window, no history and no analytics event.
		if ((new CallLogService())->isGuestUser($userId))
		{
			return self::RESPONSE_OK;
		}

		$analyticsClaimed = $this->throttle->claim($userId);

		// the option write and the call lookup both hit the database, so they wait for the response to go
		Application::getInstance()->addBackgroundJob(
			fn() => $this->recordUse($userId, $reactionId, $callUuid, $source, $analyticsClaimed)
		);

		return self::RESPONSE_OK;
	}

	/**
	 * The six reactions the picker offers first, ordered by the server.
	 *
	 * Read when the picker opens rather than handed out with the call settings: the order changes as
	 * the user reacts, and a row built at the start of a call would already be stale.
	 */
	#[Scope(ActionFilter\Scope::NOT_REST)]
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function getQuickListAction(): array
	{
		if (!Settings::isCallReactionsEnabled())
		{
			return ['items' => ReactionCatalog::SEED];
		}

		$userId = (int)($this->getCurrentUser()?->getId() ?? 0);
		if ($userId > 0)
		{
			Application::getInstance()->addBackgroundJob(fn() => $this->usageStore->flushIfDue($userId));
		}

		return ['items' => $this->quickListProvider->getQuickList($userId)];
	}

	/**
	 * Takes the onboarding show for the current user: re-checks the right to show the hint and marks
	 * it as seen in the same call, so a second client asking later is refused.
	 *
	 * Unlike the two actions above, this one is not silent for a guest: the answer decides whether the
	 * hint appears, and a guest must be refused rather than told they may show it (@see DenyGuest).
	 *
	 * Response shape:
	 * ```
	 * ['allowed' => bool] // true when this call took the show, false when it was already taken
	 * ```
	 */
	#[Scope(ActionFilter\Scope::NOT_REST)]
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	#[EnablePrefilters([new DenyGuest()])]
	public function claimOnboardingAction(): array
	{
		return ['allowed' => ReactionsOnboardingPromo::claim()];
	}

	private function recordUse(
		int $userId,
		string $reactionId,
		string $callUuid,
		string $source,
		bool $analyticsClaimed,
	): void
	{
		$this->usageStore->record($userId, $reactionId);

		if ($analyticsClaimed)
		{
			$this->sendAnalytics($userId, $callUuid, $reactionId, $source);
		}
	}

	/**
	 * Analytics hangs off the call, so an empty or unknown uuid simply means no event: an unresolved
	 * call leaves ReactionAnalytics with nothing to schedule. A call the user is not a member of is
	 * treated the same way, otherwise any known uuid would let them write events into it.
	 */
	private function sendAnalytics(int $userId, string $callUuid, string $reactionId, string $source): void
	{
		$call = $callUuid === '' ? null : CallFactory::searchActiveByUuid($callUuid);
		if ($call !== null && !$this->isCallMember($call->getId(), $userId))
		{
			$call = null;
		}

		(new ReactionAnalytics($call))->reactionUsed($reactionId, $source);
	}

	// Call::hasUser() would load every member of the call to check one.
	private function isCallMember(int $callId, int $userId): bool
	{
		return (bool)CallUserTable::getList([
			'select' => ['USER_ID'],
			'filter' => ['=CALL_ID' => $callId, '=USER_ID' => $userId],
			'limit' => 1,
		])->fetch();
	}
}
