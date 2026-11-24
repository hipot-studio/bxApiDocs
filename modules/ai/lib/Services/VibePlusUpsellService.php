<?php declare(strict_types=1);

namespace Bitrix\AI\Services;

use Bitrix\AI\Enum\VibePlusLimitState;
use Bitrix\AI\Facade\Portal;
use Bitrix\AI\Services\Dto\VibePlusLimitMessageDto;
use Bitrix\Main\Localization\Loc;
use Closure;

Loc::loadMessages(__FILE__);

/**
 * Limit notification of the Vibe+ monetization model: resolves the portal state and builds
 * the ready message for it.
 *
 * Both limit paths of the module (direct Engine::throwError() and the cloud
 * ExceededLimitService mapper) take the message from here, so the mapping
 * "state -> phrase and promoter" exists in a single place and the paths cannot diverge.
 *
 * Both also ask Portal::isWestZone() before asking this service, and that invariant belongs to them,
 * not to the resolver: the gate here reads the monetization model, which comes from the controller
 * group name, while the zone comes from the license region - on a portal with an empty group name
 * the two disagree. A third caller must check the zone as well.
 */
final class VibePlusUpsellService
{
	private const TECHNICAL_LIMIT_PROMOTER = 'limit_copilot';
	private const VIBE_PLUS_TARIFF_PROMOTER = 'limit_why_pay_tariff_vibe';

	private readonly Closure $vibePlusPolicyActiveProvider;
	private readonly Closure $onVibePlusProvider;
	private readonly Closure $currentEditionActiveProvider;
	private readonly Closure $demoAvailableProvider;

	public function __construct(
		?Closure $vibePlusPolicyActiveProvider = null,
		?Closure $onVibePlusProvider = null,
		?Closure $currentEditionActiveProvider = null,
		?Closure $demoAvailableProvider = null,
	)
	{
		$this->vibePlusPolicyActiveProvider = $vibePlusPolicyActiveProvider
			?? static fn(): bool => Portal::isVibePlusPolicyActive();
		$this->onVibePlusProvider = $onVibePlusProvider ?? static fn(): bool => Portal::isOnVibePlus();
		$this->currentEditionActiveProvider = $currentEditionActiveProvider
			?? static fn(): bool => Portal::isCurrentEditionActive();
		$this->demoAvailableProvider = $demoAvailableProvider ?? static fn(): bool => Portal::isDemoAvailable();
	}

	/**
	 * Vibe+ state of the portal. Gate is the active Vibe+ policy;
	 * everything outside it keeps the pre-Vibe+ behaviour.
	 *
	 * The west zone is NOT part of this gate: it is checked by the calling embedding points, and a
	 * new one must do the same - see the class doc.
	 */
	public function resolveState(): VibePlusLimitState
	{
		if (!($this->vibePlusPolicyActiveProvider)())
		{
			return VibePlusLimitState::NotApplicable;
		}

		if (($this->onVibePlusProvider)() && ($this->currentEditionActiveProvider)())
		{
			return VibePlusLimitState::TechnicalLimit;
		}

		return ($this->demoAvailableProvider)()
			? VibePlusLimitState::BuyWithDemo
			: VibePlusLimitState::BuyWithoutDemo;
	}

	/**
	 * Returns the ready limit message of the resolved state, or null when the portal is outside
	 * the Vibe+ model or the phrase is missing. Null means the caller keeps its current behaviour.
	 *
	 * The phrases live in the lang files of both limit paths, so the key resolves through the
	 * messages already loaded by the calling path.
	 */
	public function resolveLimitMessage(): ?VibePlusLimitMessageDto
	{
		$state = $this->resolveState();

		[$messageKey, $sliderCode] = match ($state)
		{
			VibePlusLimitState::NotApplicable => [null, null],
			VibePlusLimitState::TechnicalLimit => [
				'AI_ENGINE_ERROR_LIMIT_VIBE_TECH_WEST',
				self::TECHNICAL_LIMIT_PROMOTER,
			],
			VibePlusLimitState::BuyWithDemo => [
				'AI_ENGINE_ERROR_LIMIT_VIBE_BUY_DEMO_WEST',
				self::VIBE_PLUS_TARIFF_PROMOTER,
			],
			VibePlusLimitState::BuyWithoutDemo => [
				'AI_ENGINE_ERROR_LIMIT_VIBE_BUY_WEST',
				self::VIBE_PLUS_TARIFF_PROMOTER,
			],
		};

		if ($messageKey === null)
		{
			return null;
		}

		$message = Loc::getMessage($messageKey, ['#LINK#' => '/online/?FEATURE_PROMOTER=' . $sliderCode]);

		return is_string($message) && $message !== ''
			? new VibePlusLimitMessageDto($state, $message, $sliderCode)
			: null;
	}
}
