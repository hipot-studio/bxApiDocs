<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Application;
use Bitrix\StaffTrack\Feature;

class DayAddressQueue
{
	/**
	 * @param array{markers?: array<int, array>, track?: array<int, array>} $dayResult
	 */
	public function enqueue(array $dayResult, int $employeeId, int $viewerId): void
	{
		if (!Feature::isGeoEncryptionAvailable())
		{
			return;
		}

		$points = array_merge(
			$dayResult['markers'] ?? [],
			$this->collectRouteEnds($dayResult['track'] ?? [], $employeeId),
		);
		if (empty($points))
		{
			return;
		}

		$resolver = new AddressResolverService();
		$this->queueCheckIns($resolver, $points, $viewerId);
		$this->addBackgroundJob(static fn() => $resolver->resolveQueued());
	}

	protected function queueCheckIns(AddressResolverService $resolver, array $points, int $viewerId): void
	{
		$resolver->queueCheckIns($points, $viewerId);
	}

	protected function addBackgroundJob(callable $job): void
	{
		Application::getInstance()->addBackgroundJob($job);
	}

	/**
	 * @param array<int, array> $track
	 * @return array<int, array>
	 */
	private function collectRouteEnds(array $track, int $employeeId): array
	{
		if (empty($track))
		{
			return [];
		}

		$ends = [$track[array_key_first($track)]];
		if (count($track) > 1)
		{
			$ends[] = $track[array_key_last($track)];
		}

		return array_map(
			static fn(array $point): array => $point + ['userId' => $employeeId],
			$ends,
		);
	}
}
