<?php

namespace Bitrix\Crm\Tour\Onboarding;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Tour\Base;
use Bitrix\Crm\Tour\Mixin\HasEntitySupport;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

/**
 * The tour a newcomer meets on the first deal card they open: five steps over the zones the card is
 * built from, each addressing its target by a plain selector that has to be in the DOM by `BX.ready`.
 */
class DealCard extends Base
{
	use HasEntitySupport;

	protected const OPTION_NAME = 'onboarding-deal-card';

	private const STEP_ID_PREFIX = 'crm-onboarding-deal-card-';

	/** How long after registration a person still counts as new to CRM. */
	private const NEW_USER_PERIOD_DAYS = 30;

	private const USER_CACHE_TTL = 86400;

	private const POPUP_WIDTH = 400;

	/** @var array<int, bool> Answer of {@see self::isGoingToShow()} per entity, kept for one hit. */
	private array $goingToShowByEntityId = [];

	/**
	 * Public counterpart of {@see self::canShow()}: the hints this tour speaks for ask it to keep
	 * quiet on the opening it takes. Set the entity first, {@see HasEntitySupport::setEntityId()}.
	 */
	public function isGoingToShow(): bool
	{
		$entityId = (int)$this->entityId;

		return $this->goingToShowByEntityId[$entityId] ??= !parent::isBuildComponentDisabled();
	}

	/**
	 * Routed through the memoized answer, so that {@see Base::build()} and the hints asking
	 * {@see self::isGoingToShow()} read one decision instead of paying for it twice.
	 */
	protected function isBuildComponentDisabled(): bool
	{
		return !$this->isGoingToShow();
	}

	/**
	 * Ordered by what an answer costs: a property comparison, then the phrase file, then an option
	 * read, then the permission check, and the cached user query last.
	 */
	protected function canShow(): bool
	{
		if (!$this->isDealOpened())
		{
			return false;
		}

		if (!$this->hasPhrases())
		{
			return false;
		}

		if ($this->isUserSeenTour())
		{
			return false;
		}

		if (!$this->isOnboardingAvailable())
		{
			return false;
		}

		return $this->isUserNewToCrm();
	}

	/**
	 * Phrases are added in ru first, and Loc answers null in every language they have not reached
	 * yet. A route of empty popups would still silence the hints it speaks for, so until the
	 * translation lands the tour stays away and the card keeps its own hints.
	 */
	protected function hasPhrases(): bool
	{
		foreach ($this->getSteps() as $step)
		{
			if (($step['title'] ?? '') === '' || ($step['text'] ?? '') === '')
			{
				return false;
			}
		}

		return true;
	}

	protected function getPhrase(string $code): string
	{
		return Loc::getMessage($code) ?? '';
	}

	/**
	 * The create form has no identifier yet, and there is nothing to tell about a deal that does
	 * not exist: half of the route would point at zones the form does not have. Which openings count
	 * is up to the caller: a copy, an expose form and a recurring template pass 0 here as well.
	 */
	protected function isDealOpened(): bool
	{
		return $this->entityId > 0;
	}

	/**
	 * The rollout flag and the CRM permission, and nothing about the age of the portal: the cohort
	 * of this tour is the newcomer, and newcomers arrive on portals of any age.
	 */
	protected function isOnboardingAvailable(): bool
	{
		return $this->isFeatureEnabled() && $this->hasCrmPermission();
	}

	protected function isFeatureEnabled(): bool
	{
		return $this->isFlagEnabled(Feature\CrmDealCardOnboardingTour::class);
	}

	/**
	 * The only place the facade is asked, so that a test can see which flag the tour asks about
	 * without switching the real one.
	 */
	protected function isFlagEnabled(string $flagClassName): bool
	{
		return Feature::enabled($flagClassName);
	}

	protected function hasCrmPermission(): bool
	{
		$userId = (int)CurrentUser::get()->getId();

		return Container::getInstance()->getUserPermissions($userId)->entityType()->canReadSomeItemsInCrm();
	}

	protected function isUserNewToCrm(): bool
	{
		$registeredAt = $this->getUserRegisterDate();
		if ($registeredAt === null)
		{
			return false;
		}

		$threshold = (new DateTime())->add('-' . self::NEW_USER_PERIOD_DAYS . ' days');

		return $registeredAt->getTimestamp() >= $threshold->getTimestamp();
	}

	/**
	 * Cached: the card is a hot page, and the answer changes once in a lifetime.
	 */
	protected function getUserRegisterDate(): ?DateTime
	{
		$user = UserTable::query()
			->setSelect(['DATE_REGISTER'])
			->where('ID', (int)CurrentUser::get()->getId())
			->setCacheTtl(self::USER_CACHE_TTL)
			->fetch()
		;

		$registeredAt = $user['DATE_REGISTER'] ?? null;

		return $registeredAt instanceof DateTime ? $registeredAt : null;
	}

	/**
	 * The order below is the reading order of the card, not the structure of any one component. Only
	 * the second target is drawn by the client, hence its `ignoreIfTargetNotFound`.
	 *
	 * Only the `stages` step pins `areaPadding` to zero. Its tooltip stands below the target, so
	 * the cutout and the tooltip move together and a zero padding frames the bar itself instead of
	 * the background around it. The side steps keep the default padding on purpose: `ui.tour`
	 * offsets a side tooltip by a constant that ignores the padding, so a shrunk cutout there only
	 * widens the dark strip between the block and the tooltip.
	 */
	protected function getSteps(): array
	{
		return [
			[
				'id' => self::STEP_ID_PREFIX . 'card',
				'title' => $this->getPhrase('CRM_TOUR_ONBOARDING_DEAL_CARD_CARD_TITLE'),
				'text' => $this->getPhrase('CRM_TOUR_ONBOARDING_DEAL_CARD_CARD_TEXT'),
				'target' => '.crm-entity-card-container',
				'position' => 'right',
			],
			[
				'id' => self::STEP_ID_PREFIX . 'opportunity',
				'title' => $this->getPhrase('CRM_TOUR_ONBOARDING_DEAL_CARD_OPPORTUNITY_TITLE'),
				'text' => $this->getPhrase('CRM_TOUR_ONBOARDING_DEAL_CARD_OPPORTUNITY_TEXT'),
				'target' => '[data-cid="OPPORTUNITY_WITH_CURRENCY"]',
				'position' => 'right',
				'ignoreIfTargetNotFound' => true,
			],
			[
				'id' => self::STEP_ID_PREFIX . 'stages',
				'title' => $this->getPhrase('CRM_TOUR_ONBOARDING_DEAL_CARD_STAGES_TITLE'),
				'text' => $this->getPhrase('CRM_TOUR_ONBOARDING_DEAL_CARD_STAGES_TEXT'),
				// Not `.crm-entity-section-status-container`: that wrapper measures zero pixels high,
				// and the step would frame nothing. The reserve covers the stageflow variant of the
				// bar, which carries the outer class but not the flex row.
				'target' => '.crm-entity-section-status-container-flex',
				'reserveTargets' => ['.crm-entity-section-status-wrap'],
				'position' => 'bottom',
				'areaPadding' => 0,
			],
			[
				'id' => self::STEP_ID_PREFIX . 'timeline',
				'title' => $this->getPhrase('CRM_TOUR_ONBOARDING_DEAL_CARD_TIMELINE_TITLE'),
				'text' => $this->getPhrase('CRM_TOUR_ONBOARDING_DEAL_CARD_TIMELINE_TEXT'),
				'target' => '.crm-entity-stream-container',
				'position' => 'left',
			],
			[
				'id' => self::STEP_ID_PREFIX . 'menubar',
				'title' => $this->getPhrase('CRM_TOUR_ONBOARDING_DEAL_CARD_MENUBAR_TITLE'),
				'text' => $this->getPhrase('CRM_TOUR_ONBOARDING_DEAL_CARD_MENUBAR_TEXT'),
				'target' => '.crm-entity-stream-section-menu',
				'position' => 'left',
			],
		];
	}

	protected function getOptions(): array
	{
		return [
			'hideTourOnMissClick' => true,
			'steps' => [
				'popup' => [
					'width' => self::POPUP_WIDTH,
				],
			],
		];
	}
}
