<?php

namespace Bitrix\Crm\Service\Timeline\Navigation;

use Bitrix\Crm\Component\EntityDetails\Config\ScopeIdResolver;
use Bitrix\Crm\Filter\Filter;
use Bitrix\Crm\Filter\TimelineDataProvider;
use Bitrix\Crm\Filter\TimelineSettings;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Repository;
use Bitrix\Crm\Service\Context as ServiceContext;

final class HistoryLoader
{
	private readonly ItemIdentifier $owner;
	private readonly Context $context;

	public function __construct(int $entityTypeId, int $entityId, int $categoryId = 0)
	{
		$this->owner = new ItemIdentifier($entityTypeId, $entityId, $categoryId);
		$this->context = new Context($this->owner, Context::DESKTOP);
	}

	public function load(
		array $rawSnapshot,
		?string $cursor = null,
		int $limit = 10,
		?int $itemId = null,
	): array
	{
		$normalized = (new SnapshotNormalizer())->normalize($rawSnapshot, $this->owner, $this->context);
		if (!$normalized->isSuccess())
		{
			throw new \InvalidArgumentException($normalized->getErrorMessages()[0] ?? 'Invalid snapshot');
		}
		/** @var NavigationSnapshot $snapshot */
		$snapshot = $normalized->getData()['snapshot'];
		$fingerprint = $normalized->getData()['fingerprint'];
		$expectedScope = (new ScopeIdResolver($this->owner->getEntityTypeId(), $snapshot->owner['categoryId']))
			->setUserId($this->context->getUserId())
			->getScopeId('timeline_history')
		;
		if ($snapshot->scopeId !== $expectedScope)
		{
			throw new \UnexpectedValueException('NAV_SCOPE_CHANGED');
		}
		$cursorPayload = $cursor === null ? null : (new ContinuationToken())->verify($cursor, [
			'kind' => 'history',
			'userId' => $this->context->getUserId(),
			'owner' => $snapshot->owner,
			'scopeId' => $snapshot->scopeId,
			'viewId' => $snapshot->viewId,
			'snapshotFingerprint' => $fingerprint,
			'limit' => $limit,
		]);
		$filter = new Filter(
			$snapshot->scopeId,
			new TimelineDataProvider(new TimelineSettings([
				'ID' => $snapshot->scopeId,
				'entityId' => $this->owner->getEntityId(),
				'entityTypeId' => $this->owner->getEntityTypeId(),
			])),
		);
		$repositoryFilter = (new NativeFilterAdapter())->prepareRepositoryFilter($filter, $snapshot);
		if ($itemId !== null)
		{
			$repositoryFilter['ID'] = $itemId;
		}
		$catalogContext = (new ServiceContext())->setUserId($this->context->getUserId());
		$catalog = new TypeCatalog();
		$compiler = new PredicateCompiler($catalog, $catalogContext, $this->owner);
		$page = (new HistoryReader(new Repository($this->context), $compiler))->read(
			$snapshot,
			$cursorPayload,
			$limit,
			$repositoryFilter,
		);
		$next = null;
		if ($page['hasMore'] && $page['rawPosition'] !== null)
		{
			$next = (new ContinuationToken())->issue('history', [
				'userId' => $this->context->getUserId(), 'owner' => $snapshot->owner,
				'scopeId' => $snapshot->scopeId, 'viewId' => $snapshot->viewId,
				'snapshotFingerprint' => $fingerprint, 'limit' => $limit,
				'position' => $page['rawPosition'],
			]);
		}
		$fixed = $cursor === null
			? (new HistoryReader(new Repository($this->context), $compiler))->readFixed($snapshot, $repositoryFilter)
			: null
		;

		return [
			'available' => true,
			'normalizedSnapshot' => $snapshot->toArray(),
			'snapshotFingerprint' => $fingerprint,
			'items' => $page['items'], 'fixedItems' => $fixed,
			'cursor' => $next, 'hasMore' => $page['hasMore'],
		];
	}
}
