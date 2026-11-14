<?php

namespace Bitrix\Crm\Service\Timeline\Navigation;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Context as ServiceContext;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

final class SnapshotNormalizer
{
	public function __construct(private readonly ?TypeCatalog $catalog = null)
	{
	}

	public function normalize(array $raw, ItemIdentifier $owner, Context $context): Result
	{
		$result = new Result();
		try
		{
			$this->assertKeys($raw, ['schemaVersion', 'viewId', 'scopeId', 'owner', 'native', 'crm']);
			if (
				($raw['schemaVersion'] ?? null) !== NavigationSnapshot::SCHEMA_VERSION
				|| strlen(Json::encode($raw)) > 65536
			)
			{
				throw new \InvalidArgumentException('Invalid snapshot version or size');
			}
			$this->assertKeys($raw['owner'] ?? [], ['entityTypeId', 'entityId', 'categoryId']);
			$this->assertKeys($raw['native'] ?? [], ['fields', 'find', 'preset']);
			$crmKeys = array_keys($raw['crm'] ?? []);
			sort($crmKeys, SORT_STRING);
			if (!in_array($crmKeys, [['typeKeys', 'typeMode'], ['typeIdentities', 'typeKeys', 'typeMode']], true))
			{
				throw new \InvalidArgumentException('Unexpected snapshot crm field');
			}
			$this->assertKeys($raw['native']['preset'] ?? [], ['id', 'fingerprint']);

			$normalizedOwner = [
				'entityTypeId' => (int)$raw['owner']['entityTypeId'],
				'entityId' => (int)$raw['owner']['entityId'],
				'categoryId' => (int)$raw['owner']['categoryId'],
			];
			if (
				$normalizedOwner['entityTypeId'] !== $owner->getEntityTypeId()
				|| $normalizedOwner['entityId'] !== $owner->getEntityId()
				|| $normalizedOwner['categoryId'] < 0
			)
			{
				throw new \InvalidArgumentException('Snapshot owner does not match request owner');
			}
			$viewId = (string)($raw['viewId'] ?? '');
			if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $viewId) !== 1)
			{
				throw new \InvalidArgumentException('Invalid view id');
			}
			$scopeId = (string)($raw['scopeId'] ?? '');
			if ($scopeId === '' || strlen($scopeId) > 128)
			{
				throw new \InvalidArgumentException('Invalid scope id');
			}

			$native = $this->normalizeNative($raw['native']);
			$typeKeys = $raw['crm']['typeKeys'];
			$typeIdentities = $raw['crm']['typeIdentities'] ?? [];
			if (
				!is_array($typeKeys)
				|| !is_array($typeIdentities)
				|| count($typeKeys) > 2048
				|| count($typeKeys) !== count(array_unique($typeKeys))
			)
			{
				throw new \InvalidArgumentException('Invalid type keys');
			}
			$identityBindings = [
				'userId' => $context->getUserId(),
				'owner' => $normalizedOwner,
				'scopeId' => $scopeId,
				'viewId' => $viewId,
				'language' => defined('LANGUAGE_ID') ? LANGUAGE_ID : 'en',
				'policyVersion' => NavigationSnapshot::POLICY_VERSION,
			];
			$catalog = $this->catalog ?? new TypeCatalog();
			$catalogContext = (new ServiceContext())->setUserId($context->getUserId());
			$currentDescriptors = $catalog->identityDescriptors($catalogContext, $owner);
			$staleTypeKeys = [];
			foreach ($typeKeys as $key)
			{
				if (!is_string($key) || $key === '' || strlen($key) > 256)
				{
					throw new \InvalidArgumentException('Unknown type key');
				}
				if ($key !== 'technical')
				{
					$payload = (new SelectedTypeIdentityCodec())->verify(
						(string)($typeIdentities[$key] ?? ''),
						$identityBindings,
					);
					if ($payload->rowKey !== $key)
					{
						throw new \InvalidArgumentException('Selected type identity does not match key');
					}
					$current = $currentDescriptors[$key] ?? null;
					if ($current === null)
					{
						$staleTypeKeys[] = $key;
					}
					else
					{
						$actual = [
							$payload->sourceKind,
							$payload->sourceId,
							$payload->descriptorId,
							$payload->descriptorVersion,
							$payload->semanticDigest,
							$payload->metadataDigest,
						];
						$expected = [
							$current['sourceKind'],
							$current['sourceId'],
							$current['descriptorId'],
							$current['descriptorVersion'],
							$current['semanticDigest'],
							$current['metadataDigest'],
						];
						if ($actual !== $expected)
						{
							$staleTypeKeys[] = $key;
						}
					}
				}
			}
			$typeMode = $raw['crm']['typeMode'] ?? null;
			if (!in_array($typeMode, ['only', 'except'], true))
			{
				throw new \InvalidArgumentException('Invalid type mode');
			}
			sort($typeKeys, SORT_STRING);
			foreach ($typeIdentities as $key => $identity)
			{
				if (
					!is_string($key)
					|| $key === 'technical'
					|| !in_array($key, $typeKeys, true)
					|| !is_string($identity)
					|| $identity === ''
					|| strlen($identity) > 4096
				)
				{
					throw new \InvalidArgumentException('Invalid selected type identity');
				}
			}
			ksort($typeIdentities, SORT_STRING);
			$snapshot = new NavigationSnapshot(
				$viewId,
				$scopeId,
				$normalizedOwner,
				$native,
				[
					'typeMode' => $typeMode,
					'typeKeys' => $typeKeys,
					'typeIdentities' => $typeIdentities,
					'staleTypeKeys' => $staleTypeKeys,
				],
			);
			$fingerprint = $snapshot->toArray();
			$fingerprint['policyVersion'] = NavigationSnapshot::POLICY_VERSION;
			unset($fingerprint['native']['preset']);
			$this->sortKeys($fingerprint);
			$result->setData([
				'snapshot' => $snapshot,
				'fingerprint' => hash('sha256', Json::encode($fingerprint)),
			]);
		}
		catch (\Throwable $exception)
		{
			$result->addError(new Error($exception->getMessage(), 'NAV_INVALID_SNAPSHOT'));
		}

		return $result;
	}

	private function normalizeNative(array $native): array
	{
		$find = $native['find'] ?? null;
		if (!is_string($find) || mb_strlen($find) > 255 || !is_array($native['fields'] ?? null))
		{
			throw new \InvalidArgumentException('Invalid native filter');
		}
		$fields = [];
		foreach ($native['fields'] as $key => $value)
		{
			if (!NativeFilterAdapter::isAllowedField((string)$key))
			{
				throw new \InvalidArgumentException('Unknown native field');
			}
			$this->assertValue($value);
			$fields[(string)$key] = $value;
		}
		$fields = (new NativeFilterAdapter())->normalizeCreatedPeriod($fields);
		$id = $native['preset']['id'] ?? null;
		$fingerprint = $native['preset']['fingerprint'] ?? null;
		if ($id !== null && (!is_string($id) || strlen($id) > 128))
		{
			throw new \InvalidArgumentException('Invalid preset id');
		}
		if ($fingerprint !== null && (!is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1))
		{
			throw new \InvalidArgumentException('Invalid preset fingerprint');
		}

		return ['fields' => $fields, 'find' => $find, 'preset' => ['id' => $id, 'fingerprint' => $fingerprint]];
	}

	private function assertValue(mixed $value): void
	{
		if (is_array($value))
		{
			if (count($value) > 100)
			{
				throw new \InvalidArgumentException('Too many native values');
			}
			foreach ($value as $item) { $this->assertValue($item); }

			return;
		}
		if ((!is_scalar($value) && $value !== null) || (is_string($value) && strlen($value) > 4096))
		{
			throw new \InvalidArgumentException('Invalid native value');
		}
	}

	private function assertKeys(array $value, array $expected): void
	{
		$actual = array_keys($value);
		sort($actual, SORT_STRING);
		sort($expected, SORT_STRING);
		if ($actual !== $expected)
		{
			throw new \InvalidArgumentException('Unexpected snapshot field');
		}
	}

	private function sortKeys(array &$value): void
	{
		if (!array_is_list($value)) { ksort($value, SORT_STRING); }
		foreach ($value as &$item) { if (is_array($item)) { $this->sortKeys($item); } }
	}
}
