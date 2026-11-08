<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Intranet\Provider;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\Summary;
use Bitrix\Intranet\Public\Provider\AnnualSummary\SummaryProviderInterface;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Bitrix\Socialnetwork\V2\Internal\Repository\AnnualSummaryRepository;
use Psr\Log\LoggerInterface;
use Throwable;

if (!Loader::includeModule('intranet'))
{
	return;
}

abstract class AbstractAnnualSummaryProvider implements SummaryProviderInterface
{
	private const LOGGER_ID = 'socialnetwork.annual_summary_provider';

	private int $lastUserId = 0;

	public function __construct(
		protected readonly Date $from,
		protected readonly Date $to,
		protected readonly AnnualSummaryRepository $repository,
		private ?LoggerInterface $logger = null,
	)
	{
	}

	public function getLastUserId(): int
	{
		return $this->lastUserId;
	}

	public function provide(array $userIds): EntityCollection
	{
		if ($userIds === [])
		{
			return new EntityCollection();
		}

		$minUserId = min($userIds);
		$maxUserId = max($userIds);

		try
		{
			$counts = $this->countByUserIds($userIds);
			$summaries = new EntityCollection();
			foreach ($userIds as $userId)
			{
				$summaries->add(
					new Summary(
						userId: $userId,
						id: $this->getId(),
						total: $counts[$userId] ?? 0,
					),
				);
			}

			$this->lastUserId = $maxUserId;

			return $summaries;
		}
		catch (Throwable $exception)
		{
			$this->logException($exception, $minUserId, $maxUserId);

			return new EntityCollection();
		}
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, int>
	 */
	abstract protected function countByUserIds(array $userIds): array;

	private function logException(Throwable $exception, int $minUserId, int $maxUserId): void
	{
		$this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID);
		$this->logger?->error('Failed to calculate annual summary', [
			'providerId' => $this->getId(),
			'from' => $this->from->format('Y-m-d'),
			'to' => $this->to->format('Y-m-d'),
			'minUserId' => $minUserId,
			'maxUserId' => $maxUserId,
			'exception' => $exception,
		]);
	}
}
