<?php

namespace Bitrix\Crm\Service\Timeline\Navigation\Catalog;

use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Navigation\NavigationSnapshot;
use Bitrix\Crm\Service\Timeline\Navigation\PredicateCompiler;
use Bitrix\Crm\Service\Timeline\Navigation\SelectedTypeIdentityCodec;
use Bitrix\Crm\Service\Timeline\Navigation\TypeCatalog;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Navigation\NavigationSnapshot as SnapshotContract;
use Bitrix\Crm\Service\Timeline\Repository;
use Bitrix\Crm\Service\Timeline\Repository\Query;

final class CatalogReader
{
	private const MAX_BATCHES_PER_REQUEST = 20;
	private const REQUEST_BUDGET_NS = 200_000_000;

	public function __construct(
		private readonly ?BindingIterator $iterator = null,
		private readonly ?TypeCatalog $catalog = null,
		private readonly ?\Closure $nextBatch = null,
		private readonly ?\Closure $monotonicNowNs = null,
		private readonly ?Repository $repository = null,
	)
	{
	}

	public function next(NavigationSnapshot $snapshot, Context $context, ?array $state): array
	{
		$witnesses = $state['witnesses'] ?? [];
		$technicalWitness = $state['technicalWitness'] ?? null;
		$owner = new ItemIdentifier(
			(int)$snapshot->owner['entityTypeId'],
			(int)$snapshot->owner['entityId'],
			(int)$snapshot->owner['categoryId'],
		);
		$catalogContext = Container::getInstance()->getContext();
		$catalog = $this->catalog ?? new TypeCatalog();
		$compiler = new PredicateCompiler($catalog, $catalogContext, $owner);
		$position = $state['position'] ?? null;
		$batchCount = 0;
		$startedAt = $this->monotonicNowNs();
		do
		{
			$batch = $this->nextBatch !== null
				? ($this->nextBatch)($snapshot->owner, $position)
				: ($this->iterator ?? new BindingIterator())->next($snapshot->owner, $position);
			$batchCount++;
			$items = [];
			$positions = [];
			if ($batch['ids'] !== [])
			{
				$page = ($this->repository ?? new Repository($context))->getNavigationCandidatesPage(
					(new Query())->setFilter(['ID' => $batch['ids']])->setLimit(BindingIterator::BATCH_SIZE),
				);
				if (!$page->isSuccess())
				{
					throw new \RuntimeException('Unable to project timeline catalog');
				}
				$items = $page->getItems();
				$positions = $page->getData()['rawPositions'] ?? [];
			}
			foreach ($items as $item)
			{
				$id = (int)$item->getModel()->getId();
				$description = $compiler->describeProjectedItem($item, $positions[$id] ?? []);
				$witnesses[$description['semanticKey']] ??= $id;
				if ($description['isTechnical'] && $technicalWitness === null)
				{
					$technicalWitness = $id;
				}
			}
			$position = $batch['cursor'];
		}
		while (
			!$batch['done']
			&& $batchCount < self::MAX_BATCHES_PER_REQUEST
			&& $this->monotonicNowNs() - $startedAt < self::REQUEST_BUDGET_NS
		);
		if (!$batch['done'])
		{
			return [
				'done' => false, 'types' => [], 'selectedTypes' => [], 'technicalPresent' => false,
				'state' => [
					'position' => $position,
					'witnesses' => $witnesses,
					'technicalWitness' => $technicalWitness,
				],
			];
		}
		if (!$this->reprojectWitnesses($context, $witnesses, $technicalWitness, $compiler))
		{
			return [
				'done' => false,
				'types' => [],
				'selectedTypes' => [],
				'technicalPresent' => false,
				'state' => ['position' => null, 'witnesses' => [], 'technicalWitness' => null],
			];
		}
		$keys = array_keys($witnesses);
		$rows = $catalog->publicRows($catalogContext, $owner);
		$identities = $catalog->identityDescriptors($catalogContext, $owner);
		$identityCodec = new SelectedTypeIdentityCodec();
		$bindings = [
			'userId' => $context->getUserId(),
			'owner' => $snapshot->owner,
			'scopeId' => $snapshot->scopeId,
			'viewId' => $snapshot->viewId,
			'language' => defined('LANGUAGE_ID') ? LANGUAGE_ID : 'en',
			'policyVersion' => SnapshotContract::POLICY_VERSION,
		];
		foreach ($rows as &$row)
		{
			$identity = $identities[$row['key']] ?? null;
			if ($identity === null)
			{
				continue;
			}
			$row['identity'] = $identityCodec->issue([
				'rowKey' => $row['key'],
				...$identity,
				'expiresAt' => time() + 2592000,
				'bindings' => $bindings,
			]);
			unset($row['sourceKind'], $row['sourceId']);
		}
		unset($row);
		$staleSelected = [];
		foreach ($snapshot->crm['typeKeys'] as $key)
		{
			if ($key === 'technical')
			{
				continue;
			}
			$payload = $identityCodec->verify((string)($snapshot->crm['typeIdentities'][$key] ?? ''), $bindings);
			$current = $identities[$key] ?? null;
			if (
				$current === null
				|| [
					$payload->sourceKind,
					$payload->sourceId,
					$payload->descriptorId,
					$payload->descriptorVersion,
					$payload->semanticDigest,
					$payload->metadataDigest,
				] !== [
					$current['sourceKind'],
					$current['sourceId'],
					$current['descriptorId'],
					$current['descriptorVersion'],
					$current['semanticDigest'],
					$current['metadataDigest'],
				]
			)
			{
				$staleSelected[$key] = true;
			}
		}
		$types = array_values(array_filter(
			$rows,
			static fn(array $row): bool => array_intersect($row['selectionKeys'], $keys) !== [],
		));
		$types = array_map([$this, 'publicRow'], $types);
		$selected = array_flip(array_filter(
			$snapshot->crm['typeKeys'],
			static fn(string $key): bool => $key !== 'technical'
				&& !in_array($key, $snapshot->crm['staleTypeKeys'] ?? [], true)
				&& !isset($staleSelected[$key]),
		));
		$selectedTypes = array_values(array_filter(
			$rows,
			static fn(array $row): bool => isset($selected[$row['key']]),
		));
		$selectedTypes = array_map([$this, 'publicRow'], $selectedTypes);
		$currentSelected = array_fill_keys(array_column($selectedTypes, 'key'), true);
		foreach ($snapshot->crm['typeKeys'] as $key)
		{
			if ($key === 'technical' || isset($currentSelected[$key]))
			{
				continue;
			}
			$payload = $identityCodec->verify((string)($snapshot->crm['typeIdentities'][$key] ?? ''), $bindings);
			$metadata = array_intersect_key(
				$payload->metadata,
				array_flip(['key', 'title', 'icon', 'order']),
			);
			if (($metadata['key'] ?? null) === $key && count($metadata) === 4)
			{
				$selectedTypes[] = [...$metadata, 'removeOnly' => true];
			}
		}

		return [
			'done' => true,
			'types' => $types,
			'selectedTypes' => $selectedTypes,
			'technicalPresent' => $technicalWitness !== null,
			'state' => null,
		];
	}

	private function monotonicNowNs(): int
	{
		return $this->monotonicNowNs !== null ? ($this->monotonicNowNs)() : hrtime(true);
	}

	private function publicRow(array $row): array
	{
		return array_intersect_key(
			$row,
			array_flip(['key', 'title', 'icon', 'order', 'identity']),
		);
	}

	private function reprojectWitnesses(
		Context $context,
		array $witnesses,
		?int $technicalWitness,
		PredicateCompiler $compiler,
	): bool
	{
		$expected = [];
		foreach ($witnesses as $key => $id)
		{
			$expected[(int)$id]['semanticKey'] = $key;
		}
		if ($technicalWitness !== null)
		{
			$expected[$technicalWitness]['technical'] = true;
		}
		if ($expected === [])
		{
			return true;
		}

		$page = ($this->repository ?? new Repository($context))->getNavigationCandidatesPage(
			(new Query())->setFilter(['ID' => array_keys($expected)])->setLimit(count($expected)),
		);
		if (!$page->isSuccess())
		{
			return false;
		}
		$positions = $page->getData()['rawPositions'] ?? [];
		$actual = [];
		foreach ($page->getItems() as $item)
		{
			$id = (int)$item->getModel()->getId();
			$actual[$id] = $compiler->describeProjectedItem($item, $positions[$id] ?? []);
		}
		foreach ($expected as $id => $witness)
		{
			if (!isset($actual[$id]))
			{
				return false;
			}
			if (
				isset($witness['semanticKey'])
				&& $actual[$id]['semanticKey'] !== $witness['semanticKey']
			)
			{
				return false;
			}
			if (($witness['technical'] ?? false) && !$actual[$id]['isTechnical'])
			{
				return false;
			}
		}

		return true;
	}
}
