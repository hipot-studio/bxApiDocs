<?php

namespace Bitrix\Crm\Controller\Timeline;

use Bitrix\Crm\Component\EntityDetails\Config\ScopeIdResolver;
use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Navigation\Availability;
use Bitrix\Crm\Service\Timeline\Navigation\Catalog\CatalogReader;
use Bitrix\Crm\Service\Timeline\Navigation\Catalog\CatalogRunBinding;
use Bitrix\Crm\Service\Timeline\Navigation\Catalog\CatalogRunStore;
use Bitrix\Crm\Service\Timeline\Navigation\ContinuationToken;
use Bitrix\Crm\Service\Timeline\Navigation\HistoryLoader;
use Bitrix\Crm\Service\Timeline\Navigation\NativeBootstrap;
use Bitrix\Crm\Service\Timeline\Navigation\NavigationSnapshot;
use Bitrix\Crm\Service\Timeline\Navigation\SnapshotNormalizer;
use Bitrix\Crm\Service\Timeline\Navigation\TypeCatalog;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Engine\ActionFilter\ContentType;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Error;

class Navigation extends Base
{
	private const ERROR_MESSAGES = [
		'NAV_SCOPE_CHANGED' => 'Timeline scope changed',
		'NAV_CURSOR_EXPIRED' => 'Timeline cursor expired',
		'NAV_RETRYABLE' => 'Unable to load timeline navigation',
		'NAV_INVALID_REQUEST' => 'Invalid timeline navigation request',
		'NAV_CURSOR_INVALID' => 'Invalid timeline cursor',
		'NAV_CATALOG_EXPIRED' => 'Timeline catalog cursor expired',
		'NAV_CATALOG_STALE' => 'Timeline catalog changed',
		'NAV_CATALOG_OVERFLOW' => 'Timeline catalog is too large',
	];

	protected function getDefaultPreFilters(): array
	{
		return [...parent::getDefaultPreFilters(), new ContentType([ContentType::JSON])];
	}

	public function getAutoWiredParameters(): array
	{
		return [
			...parent::getAutoWiredParameters(),
			new ExactParameter(
				ItemIdentifier::class,
				'owner',
				static function(string $className, array $snapshot = [], array $owner = []): ?ItemIdentifier
				{
					$rawOwner = $snapshot['owner'] ?? $owner;
					if (!is_array($rawOwner))
					{
						return null;
					}

					return $className::createByParams(
						(int)($rawOwner['entityTypeId'] ?? 0),
						(int)($rawOwner['entityId'] ?? 0),
					);
				},
			),
		];
	}

	public function loadHistoryAction(
		ItemIdentifier $owner,
		array $snapshot,
		?string $cursor = null,
		int $limit = 10,
	): ?array
	{
		if (!$this->validateReadPermission($owner))
		{
			return null;
		}
		if (!(new Availability())->isAvailable($owner->getEntityTypeId(), $owner->getEntityId()))
		{
			return ['available' => false];
		}
		try
		{
			return (new HistoryLoader(
				$owner->getEntityTypeId(),
				$owner->getEntityId(),
				$owner->getCategoryId() ?? 0,
			))->load($snapshot, $cursor, $limit);
		}
		catch (\UnexpectedValueException)
		{
			return $this->fail('NAV_SCOPE_CHANGED');
		}
		catch (\RuntimeException $exception)
		{
			$code = str_contains($exception->getMessage(), 'expired')
				? 'NAV_CURSOR_EXPIRED'
				: 'NAV_RETRYABLE';

			return $this->fail($code);
		}
		catch (\Throwable)
		{
			return $this->fail('NAV_INVALID_REQUEST');
		}
	}

	public function loadCatalogAction(ItemIdentifier $owner, array $snapshot, ?string $cursor = null): ?array
	{
		if (!$this->validateReadPermission($owner))
		{
			return null;
		}
		if (!(new Availability())->isAvailable($owner->getEntityTypeId(), $owner->getEntityId()))
		{
			return ['available' => false];
		}
		try
		{
			$context = new Context($owner, Context::DESKTOP);
			$normalized = (new SnapshotNormalizer())->normalize($snapshot, $owner, $context);
			if (!$normalized->isSuccess())
			{
				throw new \InvalidArgumentException('Invalid snapshot');
			}
			/** @var NavigationSnapshot $dto */
			$dto = $normalized->getData()['snapshot'];
			$expectedScope = (new ScopeIdResolver($owner->getEntityTypeId(), $owner->getCategoryId()))
				->setUserId($context->getUserId())->getScopeId('timeline_history')
			;
			if ($dto->scopeId !== $expectedScope)
			{
				return $this->fail('NAV_SCOPE_CHANGED');
			}
			$typeCatalog = new TypeCatalog();
			$catalogDigest = $typeCatalog->catalogDigest(Container::getInstance()->getContext(), $owner);
			$binding = new CatalogRunBinding([
				'userId' => $context->getUserId(),
				'owner' => $dto->owner,
				'scopeId' => $dto->scopeId,
				'viewId' => $dto->viewId,
				'language' => defined('LANGUAGE_ID') ? LANGUAGE_ID : 'en',
				'policyVersion' => NavigationSnapshot::POLICY_VERSION,
			]);
			$store = new CatalogRunStore();
			if ($cursor !== null)
			{
				$payload = (new ContinuationToken())->verify($cursor, [
					'kind' => 'catalog', 'userId' => $context->getUserId(), 'owner' => $dto->owner,
					'scopeId' => $dto->scopeId, 'viewId' => $dto->viewId,
					'catalogDigest' => $catalogDigest,
				]);
				$lease = $store->load(
					(string)$payload->get('runId'),
					(int)$payload->get('expectedVersion'),
					$binding,
					$catalogDigest,
				);
			}
			else
			{
				$lease = $store->start($binding, $catalogDigest);
			}
			$page = $this->createCatalogReader($typeCatalog)->next(
				$dto,
				$context,
				$lease->state->position ?: null,
			);
			$next = null;
			if (!$page['done'])
			{
				$lease = $store->update($lease, $page['state']);
				$next = (new ContinuationToken())->issue('catalog', [
					'userId' => $context->getUserId(), 'owner' => $dto->owner,
					'scopeId' => $dto->scopeId, 'viewId' => $dto->viewId,
					'catalogDigest' => $catalogDigest,
					'runId' => $lease->state->runId,
					'expectedVersion' => $lease->state->version,
					'position' => [],
				]);
			}
			else
			{
				$store->finalize($lease, [...$page['types'], ...$page['selectedTypes']]);
			}

			return [
				'available' => true, 'done' => $page['done'], 'cursor' => $next,
				'types' => $page['types'], 'selectedTypes' => $page['selectedTypes'],
				'technicalPresent' => $page['technicalPresent'],
			];
		}
		catch (\InvalidArgumentException $exception)
		{
			return $this->fail(
				str_contains($exception->getMessage(), 'NAV_CATALOG_STALE')
					? 'NAV_CATALOG_STALE'
					: 'NAV_CURSOR_INVALID',
			);
		}
		catch (\RuntimeException $exception)
		{
			$code = match (true)
			{
				str_contains($exception->getMessage(), 'NAV_CATALOG_EXPIRED') => 'NAV_CATALOG_EXPIRED',
				str_contains($exception->getMessage(), 'NAV_CATALOG_STALE') => 'NAV_CATALOG_STALE',
				str_contains($exception->getMessage(), 'NAV_CATALOG_OVERFLOW') => 'NAV_CATALOG_OVERFLOW',
				str_contains($exception->getMessage(), 'expired') => 'NAV_CURSOR_EXPIRED',
				default => 'NAV_RETRYABLE',
			};

			return $this->fail($code);
		}
		catch (\Throwable)
		{
			return $this->fail('NAV_CURSOR_INVALID');
		}
	}

	public function loadHistoryItemAction(
		ItemIdentifier $owner,
		array $snapshot,
		int $id,
		?string $cursor = null,
	): ?array
	{
		if (!$this->validateReadPermission($owner))
		{
			return null;
		}
		if (!(new Availability())->isAvailable($owner->getEntityTypeId(), $owner->getEntityId()))
		{
			return ['available' => false];
		}
		if ($id <= 0)
		{
			return $this->fail('NAV_INVALID_REQUEST');
		}
		try
		{
			$loader = new HistoryLoader(
				$owner->getEntityTypeId(),
				$owner->getEntityId(),
				$owner->getCategoryId() ?? 0,
			);
			$result = $loader->load($snapshot, null, 1, $id);
			$itemAfterCursor = $cursor !== null && $result['items'] !== []
				? $loader->load($snapshot, $cursor, 10, $id)['items'] !== []
				: false;

			return [
				'available' => true,
				'item' => $result['items'][0] ?? null,
				'fixedItem' => $result['fixedItems'][0] ?? null,
				'insertHistoryItem' => !$itemAfterCursor,
			];
		}
		catch (\UnexpectedValueException)
		{
			return $this->fail('NAV_SCOPE_CHANGED');
		}
		catch (\RuntimeException $exception)
		{
			$code = str_contains($exception->getMessage(), 'expired')
				? 'NAV_CURSOR_EXPIRED'
				: 'NAV_RETRYABLE';

			return $this->fail($code);
		}
		catch (\Throwable)
		{
			return $this->fail('NAV_INVALID_REQUEST');
		}
	}

	public function bootstrapAction(ItemIdentifier $owner, string $scopeId, string $viewId): ?array
	{
		if (!$this->validateReadPermission($owner))
		{
			return null;
		}
		try
		{
			return (new NativeBootstrap())->load($owner, $viewId);
		}
		catch (\Throwable)
		{
			return $this->fail('NAV_RETRYABLE');
		}
	}

	protected function createCatalogReader(TypeCatalog $typeCatalog): CatalogReader
	{
		return new CatalogReader(null, $typeCatalog);
	}

	private function fail(string $code): null
	{
		$this->addError(new Error(self::ERROR_MESSAGES[$code] ?? self::ERROR_MESSAGES['NAV_RETRYABLE'], $code));

		return null;
	}
}
