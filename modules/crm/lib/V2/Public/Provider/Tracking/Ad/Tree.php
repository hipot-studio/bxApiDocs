<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Tracking\Ad;

use Bitrix\Crm\Tracking\Source\Level\Type;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Period;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Refusal;
use Bitrix\Crm\V2\Internal\Service\Tracking\Ad\Report;
use Bitrix\Crm\V2\Internal\Service\Tracking\TrackingAccess;
use Bitrix\Main\Security\Sign\Signer;

final class Tree
{
	private const SALT = 'crm.tracking.ad.tree';

	public function __construct(
		private readonly Report $report = new Report(),
		private readonly TrackingAccess $access = new TrackingAccess(),
	)
	{
	}

	/** @return array{items: array, next: ?string} */
	public function list(
		int $userId,
		int $sourceId,
		Period $period,
		string $level = 'campaign',
		?int $parent = null,
		array $pagination = [],
	): array
	{
		$this->access->assertCanRead($userId);
		$this->report->source($sourceId);
		$uiLevel = match ($level) {
			'campaign' => Type::Campaign,
			'group' => Type::Adgroup,
			'keyword' => Type::Keyword,
			default => throw Refusal::OperationConflict->exception(),
		};
		if ($parent !== null && ($parent < 1 || $parent > 2147483647 || $uiLevel === Type::Campaign))
		{
			throw Refusal::OperationConflict->exception();
		}

		$limit = $pagination['limit'] ?? 100;
		$cursor = $pagination['cursor'] ?? null;
		if (!is_int($limit) || $limit < 1 || $limit > 100
			|| ($cursor !== null && (!is_string($cursor) || $cursor === '' || strlen($cursor) > 4096))
			|| array_diff(array_keys($pagination), ['limit', 'cursor']) !== [])
		{
			throw Refusal::OperationConflict->exception();
		}
		$context = [$userId, $sourceId, $period->dateFrom, $period->dateTo, $level, $parent];
		$lastId = 0;
		$signer = new Signer();
		if ($cursor !== null)
		{
			try
			{
				$previous = json_decode($signer->unsign($cursor, self::SALT), true, 16, JSON_THROW_ON_ERROR);
			}
			catch (\Exception)
			{
				throw Refusal::OperationConflict->exception();
			}
			if (!is_array($previous) || ($previous['context'] ?? null) !== $context
				|| !is_int($previous['lastId'] ?? null)
				|| $previous['lastId'] < 1 || $previous['lastId'] > 2147483647)
			{
				throw Refusal::OperationConflict->exception();
			}
			$lastId = $previous['lastId'];
		}

		$childIds = $this->report->pageChildIds($sourceId, $period, $uiLevel, $parent, $lastId, $limit);
		if ($cursor !== null && $childIds === [])
		{
			throw Refusal::OperationConflict->exception();
		}
		$hasMore = count($childIds) > $limit;
		$pageIds = array_slice($childIds, 0, $limit);
		$items = $pageIds === [] ? [] : $this->report->builder($sourceId, $period)
			->getRows($uiLevel, $parent, $pageIds);
		usort($items, static fn(array $left, array $right): int => $left['id'] <=> $right['id']);
		$next = $hasMore
			? $signer->sign(json_encode([
				'context' => $context,
				'lastId' => $pageIds[count($pageIds) - 1],
			], JSON_THROW_ON_ERROR), self::SALT)
			: null
		;

		return ['items' => $items, 'next' => $next];
	}
}
