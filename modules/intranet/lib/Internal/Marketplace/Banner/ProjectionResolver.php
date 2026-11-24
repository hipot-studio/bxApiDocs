<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Marketplace\Banner;

use Bitrix\Intranet\Internal\Marketplace\Banner\Local\LocalProjectionProvider;
use Bitrix\Intranet\Internal\Marketplace\Banner\Managed\Campaign;
use Bitrix\Intranet\Internal\Marketplace\Banner\Managed\RemoteConfiguration;
use Bitrix\Intranet\Internal\Marketplace\Banner\Managed\RemoteStatus;
use Bitrix\Main\Loader;

final class ProjectionResolver
{
	private const MAX_TTL = 86400;

	public function __construct(
		private readonly LocalProjectionProvider $localProjectionProvider,
		private readonly ProjectionCache $projectionCache,
		private readonly string $currentTariff,
		private readonly \DateTimeImmutable $now,
		private readonly ?RemoteConfiguration $remoteConfiguration = null,
	)
	{
	}

	public static function isAvailable(): bool
	{
		return Loader::includeModule('bitrix24') && Loader::includeModule('market');
	}

	public static function createDefault(): ?self
	{
		if (!self::isAvailable())
		{
			return null;
		}

		return new self(
			new LocalProjectionProvider(),
			new ProjectionCache(),
			(string)\CBitrix24::getLicenseType(),
			new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
			new RemoteConfiguration(),
		);
	}

	/**
	 * @return array{projection: BannerProjection|null, refreshRequired: bool}
	 */
	public function resolveInitial(string $language): array
	{
		try
		{
			$now = $this->now;
			$currentTariff = $this->currentTariff;

			$snapshot = $this->getRemoteConfiguration()->read();
			if ($snapshot === null || !$this->isFreshSnapshot($snapshot, $now))
			{
				try
				{
					$projection = $this->localProjectionProvider->resolve($now)['projection'];
				}
				catch (\Throwable)
				{
					$projection = null;
				}

				return [
					'projection' => $projection,
					'refreshRequired' => true,
				];
			}

			$cached = $this->projectionCache->read($language, $snapshot['checkedAt'], $now->getTimestamp());
			if ($cached['hit'])
			{
				return [
					'projection' => $cached['projection'],
					'refreshRequired' => false,
				];
			}

			$projection = $this->resolveFromSnapshot($snapshot, $currentTariff, $language, $now, true);

			return [
				'projection' => $projection,
				'refreshRequired' => false,
			];
		}
		catch (\Throwable)
		{
			return [
				'projection' => null,
				'refreshRequired' => false,
			];
		}
	}

	public function resolveManaged(string $language): ?BannerProjection
	{
		try
		{
			$now = $this->now;
			$currentTariff = $this->currentTariff;

			$snapshot = $this->getRemoteConfiguration()->readFresh($now->getTimestamp());
			if ($snapshot === null)
			{
				return null;
			}

			$projection = $this->resolveFromSnapshot($snapshot, $currentTariff, $language, $now, false);

			return $projection?->source === 'managed' ? $projection : null;
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * @param array{status: RemoteStatus, checkedAt: int, campaign: Campaign|null} $snapshot
	 */
	private function resolveFromSnapshot(
		array $snapshot,
		string $currentTariff,
		string $language,
		\DateTimeImmutable $now,
		bool $allowLocalFallback,
	): ?BannerProjection
	{
		$campaign = $snapshot['status'] === RemoteStatus::Campaign
			? $snapshot['campaign']
			: null;

		$snapshotExpiresAt = $this->createSnapshotExpiresAt($snapshot['checkedAt']);
		if ($this->isManagedCampaignApplicable($campaign, $currentTariff, $language, $now))
		{
			$projection = $this->createManagedProjection($campaign, $language);
			$validUntil = $this->nearestBoundary($now, [$campaign->showTo, $snapshotExpiresAt]);
			$this->projectionCache->write($language, $projection, $validUntil, $now, $snapshot['checkedAt']);

			return $projection;
		}

		if (!$allowLocalFallback)
		{
			return null;
		}

		$localResolution = $this->localProjectionProvider->resolve($now);
		$projection = $localResolution['projection'];
		$validUntil = $this->nearestBoundary(
			$now,
			[
				$localResolution['nextBoundary'],
				$this->getFutureManagedBoundary($campaign, $currentTariff, $language, $now),
				$snapshotExpiresAt,
			],
		);
		$this->projectionCache->write($language, $projection, $validUntil, $now, $snapshot['checkedAt']);

		return $projection;
	}

	/**
	 * @param array{status: RemoteStatus, checkedAt: int, campaign: Campaign|null} $snapshot
	 */
	private function isFreshSnapshot(array $snapshot, \DateTimeImmutable $now): bool
	{
		return $this->getRemoteConfiguration()->isFresh($snapshot['checkedAt'], $now->getTimestamp());
	}

	private function createSnapshotExpiresAt(int $checkedAt): \DateTimeImmutable
	{
		return (new \DateTimeImmutable('@' . ($checkedAt + RemoteConfiguration::FRESH_TTL)))
			->setTimezone(new \DateTimeZone('UTC'))
		;
	}

	private function getRemoteConfiguration(): RemoteConfiguration
	{
		return $this->remoteConfiguration ?? new RemoteConfiguration();
	}

	private function isManagedCampaignApplicable(
		?Campaign $campaign,
		string $currentTariff,
		string $language,
		\DateTimeImmutable $now,
	): bool
	{
		return $campaign !== null
			&& in_array($currentTariff, $campaign->tariffs, true)
			&& array_key_exists($language, $campaign->localizations)
			&& $campaign->isActiveAt($now);
	}

	private function createManagedProjection(Campaign $campaign, string $language): BannerProjection
	{
		$localization = $campaign->localizations[$language];

		return new BannerProjection(
			$campaign->id,
			'managed',
			'managed',
			$localization['title'],
			$localization['description'],
			'link',
			$localization['buttonLabel'],
			$localization['buttonUrl'],
			$campaign->backgroundImageUrl ?: null,
			$campaign->textColor ?: null,
			$campaign->buttonBackgroundColor ?: null,
			$campaign->buttonTextColor ?: null,
		);
	}

	private function getFutureManagedBoundary(
		?Campaign $campaign,
		string $currentTariff,
		string $language,
		\DateTimeImmutable $now,
	): ?\DateTimeImmutable
	{
		if (
			$campaign === null
			|| !in_array($currentTariff, $campaign->tariffs, true)
			|| !array_key_exists($language, $campaign->localizations)
			|| $campaign->showFrom <= $now
		)
		{
			return null;
		}

		return $campaign->showFrom;
	}

	/**
	 * @param array<?\DateTimeImmutable> $boundaries
	 */
	private function nearestBoundary(\DateTimeImmutable $now, array $boundaries): \DateTimeImmutable
	{
		$validUntil = $now->modify('+' . self::MAX_TTL . ' seconds');
		foreach ($boundaries as $boundary)
		{
			if ($boundary !== null && $boundary > $now && $boundary < $validUntil)
			{
				$validUntil = $boundary;
			}
		}

		return $validUntil;
	}
}
