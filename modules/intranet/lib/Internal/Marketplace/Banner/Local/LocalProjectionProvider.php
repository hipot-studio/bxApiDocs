<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Marketplace\Banner\Local;

use Bitrix\Intranet\Internal\Integration\Market\BannerStateProvider as MarketBannerStateProvider;
use Bitrix\Intranet\Internal\Integration\Rest\BannerStateProvider as RestBannerStateProvider;
use Bitrix\Intranet\Internal\Marketplace\Banner\BannerProjection;
use Bitrix\Main\Localization\Loc;
use Bitrix\Market\Link;

final class LocalProjectionProvider
{
	public const TRIAL_AVAILABLE = 'trial_available';
	public const TRIAL_ACTIVE = 'trial_active';
	public const SUBSCRIPTION_INACTIVE = 'subscription_inactive';
	public const NO_BANNER = 'no_banner';

	private const SOURCE = 'local';
	private const LINK_SOURCE = 'feed_banner';

	private readonly string $marketUrl;
	private readonly MarketBannerStateProvider $marketStateProvider;
	private readonly RestBannerStateProvider $restStateProvider;

	public function __construct(
		?string $marketUrl = null,
		?MarketBannerStateProvider $marketStateProvider = null,
		?RestBannerStateProvider $restStateProvider = null,
	)
	{
		$this->marketUrl = $marketUrl ?? Link::getDir(self::LINK_SOURCE);
		$this->marketStateProvider = $marketStateProvider ?? new MarketBannerStateProvider();
		$this->restStateProvider = $restStateProvider ?? new RestBannerStateProvider();
	}

	/**
	 * @return array{projection: BannerProjection|null, nextBoundary: \DateTimeImmutable|null}
	 */
	public function resolve(?\DateTimeImmutable $now = null): array
	{
		$now ??= new \DateTimeImmutable();
		$state = $this->resolveState();
		$nextBoundary = $this->resolveNextBoundary($now);
		$projection = match ($state)
		{
			self::TRIAL_AVAILABLE => $this->makeProjection(
				self::TRIAL_AVAILABLE,
				'activate_trial',
				null,
			),
			self::TRIAL_ACTIVE => $this->makeProjection(
				self::TRIAL_ACTIVE,
				'link',
				$this->marketUrl,
			),
			self::SUBSCRIPTION_INACTIVE => $this->makeProjection(
				self::SUBSCRIPTION_INACTIVE,
				'show_subscription',
				null,
			),
			self::NO_BANNER => null,
		};

		return [
			'projection' => $projection,
			'nextBoundary' => $nextBoundary,
		];
	}

	private function resolveState(): string
	{
		if (!$this->marketStateProvider->hasSubscription())
		{
			return self::NO_BANNER;
		}

		$subscriptionActive = $this->marketStateProvider->isSubscriptionActive();
		$trialActive = $subscriptionActive && $this->restStateProvider->isTrialActive();

		if ($trialActive)
		{
			return self::TRIAL_ACTIVE;
		}

		if ($subscriptionActive)
		{
			return self::NO_BANNER;
		}

		return $this->marketStateProvider->isTrialAvailable()
			? self::TRIAL_AVAILABLE
			: self::SUBSCRIPTION_INACTIVE;
	}

	private function resolveNextBoundary(\DateTimeImmutable $now): ?\DateTimeImmutable
	{
		$finishDate = $this->marketStateProvider->getFinishDate();
		if ($finishDate === null)
		{
			return null;
		}

		$boundaryTimestamp = (clone $finishDate)->add('P1D')->getTimestamp();
		if ($boundaryTimestamp <= $now->getTimestamp())
		{
			return null;
		}

		return (new \DateTimeImmutable('@' . $boundaryTimestamp))->setTimezone($now->getTimezone());
	}

	private function makeProjection(string $state, string $actionType, ?string $actionUrl): BannerProjection
	{
		$hasInstalledApps = $state === self::SUBSCRIPTION_INACTIVE
			&& $this->restStateProvider->hasInstalledApps();
		$buttonState = $state === self::SUBSCRIPTION_INACTIVE && !$hasInstalledApps
			? self::TRIAL_ACTIVE
			: $state;
		$buttonPhrasePrefix = 'INTRANET_MARKETPLACE_BANNER_' . mb_strtoupper($buttonState);

		return new BannerProjection(
			$state,
			self::SOURCE,
			$state,
			(string)Loc::getMessage('INTRANET_MARKETPLACE_BANNER_TITLE'),
			$this->getDescription($state, $hasInstalledApps),
			$actionType,
			(string)Loc::getMessage($buttonPhrasePrefix . '_BUTTON'),
			$actionUrl,
		);
	}

	private function getDescription(string $state, bool $hasInstalledApps): string
	{
		$messageId = match ($state)
		{
			self::TRIAL_AVAILABLE => 'INTRANET_MARKETPLACE_BANNER_TRIAL_AVAILABLE_DESCRIPTION',
			self::TRIAL_ACTIVE => 'INTRANET_MARKETPLACE_BANNER_APPLICATIONS_DESCRIPTION',
			self::SUBSCRIPTION_INACTIVE => $hasInstalledApps
				? 'INTRANET_MARKETPLACE_BANNER_INSTALLED_APPLICATIONS_DESCRIPTION'
				: 'INTRANET_MARKETPLACE_BANNER_APPLICATIONS_DESCRIPTION',
		};

		return (string)Loc::getMessage($messageId);
	}
}
