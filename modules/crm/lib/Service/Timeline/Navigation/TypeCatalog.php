<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Navigation;

use Bitrix\Crm\Filter\TimelineEntryCategory;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Context;
use Bitrix\Crm\Service\Timeline\Item\Activity\Bizproc;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ActivityProviderTypeCatalogInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ProviderRegistry;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ProviderRegistryInterface;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

Loc::loadMessages(__FILE__);

final class TypeCatalog
{
	private static ?array $defaultRows = null;
	private array $rowsByContext = [];
	private array $semanticDescriptorsByContext = [];
	private array $identityDescriptorsByContext = [];
	private array $ownershipDescriptorsByContext = [];
	private ?string $activeContextKey = null;
	private ?Context $activeContext = null;
	private ?ItemIdentifier $activeOwner = null;
	private const PRECEDENCE_VERSION = 1;
	private const AGGREGATE_SELECTIONS = [
		'activity-meeting' => [
			'activity-meeting',
			'activity-calendar-sharing',
			'activity-zoom',
		],
		'activity-call' => ['activity-call'],
		'activity-task' => ['activity-task'],
		'activity-bizproc-task' => ['activity-bizproc-task'],
		'biz-process' => ['biz-process'],
		'application' => ['application'],
		'sign-document' => ['sign-document'],
		'sign-b2e-document' => ['sign-b2e-document'],
		'waiting' => ['waiting'],
		'delivery' => ['delivery'],
		'booking' => ['booking'],
		'wait-list-item' => ['wait-list-item'],
	];

	public function __construct(private readonly ?ProviderRegistryInterface $providerRegistry = null)
	{
	}

	public static function keys(): array
	{
		return array_column((new self())->publicRows(), 'key');
	}

	public static function has(string $key): bool
	{
		return $key === 'technical' || (new self())->descriptorOrNull($key) !== null;
	}

	public function descriptor(string $key): array
	{
		$descriptor = $this->descriptorOrNull($key);
		if ($descriptor === null)
		{
			throw new \InvalidArgumentException('Unknown timeline navigation type');
		}

		return $descriptor;
	}

	public function selectionKeys(
		string $key,
		?Context $context = null,
		?ItemIdentifier $owner = null,
	): array
	{
		$descriptor = $this->semanticDescriptor($key, $context, $owner);
		if ($descriptor === null)
		{
			return [];
		}

		return $descriptor['selectionKeys'];
	}

	public function classify(
		array $row,
		?Context $context = null,
		?ItemIdentifier $owner = null,
	): string
	{
		return $this->resolveOwnership(SafeTimelineDiscriminator::fromStoredFields($row), $context, $owner);
	}

	public function catalogDigest(?Context $context = null, ?ItemIdentifier $owner = null): string
	{
		return hash('sha256', Json::encode([
			'precedenceVersion' => self::PRECEDENCE_VERSION,
			'descriptors' => $this->semanticComposition($context, $owner),
		]));
	}

	public function candidateExpression(
		string $key,
		?Context $context = null,
		?ItemIdentifier $owner = null,
	): ?CandidateExpression
	{
		return $this->semanticDescriptor($key, $context, $owner)['candidate'] ?? null;
	}

	public function identityDescriptor(
		string $key,
		?Context $context = null,
		?ItemIdentifier $owner = null,
	): ?array
	{
		return $this->identityDescriptors($context, $owner)[$key] ?? null;
	}

	public function identityDescriptors(?Context $context = null, ?ItemIdentifier $owner = null): array
	{
		$contextKey = $this->effectiveContextKey($context, $owner);
		if (isset($this->identityDescriptorsByContext[$contextKey]))
		{
			return $this->identityDescriptorsByContext[$contextKey];
		}
		$result = [];
		foreach ($this->semanticDescriptors($context, $owner) as $descriptor)
		{
			$metadata = array_intersect_key($descriptor, array_flip(['key', 'title', 'icon', 'order']));
			$result[$descriptor['key']] = [
				'sourceKind' => $descriptor['sourceKind'],
				'sourceId' => $descriptor['sourceId'],
				'descriptorId' => $descriptor['descriptorId'],
				'descriptorVersion' => $descriptor['descriptorVersion'],
				'semanticDigest' => hash('sha256', Json::encode([
					'candidate' => $descriptor['candidate']->signature(),
					'exact' => $descriptor['exact']->signature(),
					'selectionKeys' => $descriptor['selectionKeys'],
				])),
				'metadataDigest' => hash('sha256', Json::encode($metadata)),
				'metadata' => $metadata,
			];
		}

		return $this->identityDescriptorsByContext[$contextKey] = $result;
	}

	public function publicRows(?Context $context = null, ?ItemIdentifier $owner = null): array
	{
		$contextKey = $this->contextKey($context, $owner);
		if (isset($this->rowsByContext[$contextKey]))
		{
			return $this->rowsByContext[$contextKey];
		}
		$useDefaultCache = $this->providerRegistry === null && $context === null && $owner === null;
		if ($useDefaultCache && self::$defaultRows !== null)
		{
			return self::$defaultRows;
		}
		$rows = [];
		foreach (TimelineEntryCategory::getDescriptors() as $descriptor)
		{
			$key = $descriptor->getId();
			if ($key === TimelineEntryCategory::ACTIVITY_ZOOM)
			{
				continue;
			}
			$rows[$key] = $this->row(
				$key,
				$descriptor->getTitle(),
				$descriptor->getIcon() ?? $this->iconFor('category', $key),
				$descriptor->getOrder(),
				self::AGGREGATE_SELECTIONS[$key] ?? [$key],
				'category',
				$key,
			);
		}
		$bizprocTaskTitle = Bizproc\Task::getTitleText();
		if (is_string($bizprocTaskTitle) && $bizprocTaskTitle !== '')
		{
			$rows['activity-bizproc-task'] = $this->row(
				'activity-bizproc-task',
				$bizprocTaskTitle,
				'o-empty-message',
				count($rows),
				['activity-bizproc-task'],
				'aggregate',
				'activity-bizproc-task',
			);
		}

		$registry = $this->providerRegistry ?? new ProviderRegistry();
		$order = count($rows);
		foreach ($registry->getAll(true) as $providerId => $providerDescriptor)
		{
			if ($providerDescriptor->isMembershipOnly())
			{
				continue;
			}
			try
			{
				$provider = $registry->get($providerId, true);
				if ($provider === null)
				{
					continue;
				}
				if ($provider instanceof ActivityProviderTypeCatalogInterface)
				{
					foreach ($provider->getTimelineTypeDescriptors() as $typeDescriptor)
					{
						if ($context === null || $owner === null)
						{
							continue;
						}
						if (!$provider->canExposeTimelineType($typeDescriptor->getId(), $context, $owner))
						{
							continue;
						}
						$aggregateKey = $this->aggregateKeyForProvider($providerId);
						$key = $aggregateKey
							?? 'provider-type:' . strtolower($providerId) . ':' . $typeDescriptor->getId();
						if (isset($rows[$key]))
						{
							$rows[$key]['selectionKeys'] = array_values(array_unique([
								...$rows[$key]['selectionKeys'],
								$key,
							]));

							continue;
						}
						$rows[$key] = $this->row(
							$key,
							$typeDescriptor->getTitle(),
							$typeDescriptor->getIcon() ?? $this->iconFor('providerType', $key),
							$order + $typeDescriptor->getOrder(),
							[$key],
							'providerType',
							$providerId . ':' . $typeDescriptor->getId(),
						);
					}
				}
				if ($providerId === 'CRM_BOOKING')
				{
					continue;
				}
				$aggregateKey = $this->aggregateKeyForProvider($providerId);
				if ($aggregateKey !== null && isset($rows[$aggregateKey]))
				{
					continue;
				}
				$title = trim($provider->getName());
				if ($title === '')
				{
					continue;
				}
				$key = $aggregateKey ?? $this->providerRowKey($providerId);
				if (!isset($rows[$key]))
				{
					$rows[$key] = $this->row(
						$key,
						$title,
						$this->iconFor('provider', $key),
						$order++,
						[$key],
						'provider',
						$providerId,
					);
				}
			}
			catch (\Throwable)
			{
				continue;
			}
		}

		$rows['other-event'] = $this->row(
			'other-event',
			(string)Loc::getMessage('CRM_TIMELINE_NAVIGATION_TYPE_CATALOG_OTHER_EVENT'),
			'o-message',
			PHP_INT_MAX,
			['other-event'],
			'family',
			'other-event',
		);
		$rows = array_values($rows);
		usort(
			$rows,
			static fn(array $left, array $right): int => [
				$left['order'],
				$left['key'],
			] <=> [$right['order'], $right['key']],
		);

		if ($useDefaultCache)
		{
			self::$defaultRows = $rows;
		}
		$this->rowsByContext[$contextKey] = $rows;
		if ($context !== null && $owner !== null)
		{
			$this->activeContextKey = $contextKey;
			$this->activeContext = $context;
			$this->activeOwner = $owner;
		}

		return $rows;
	}

	private function descriptorOrNull(string $key): ?array
	{
		foreach ($this->publicRows() as $row)
		{
			if ($row['key'] === $key)
			{
				return [
					...$row,
					'candidatePredicate' => ['key' => $key, 'superset' => true, 'typeIds' => []],
					'projectedMatch' => static fn(array $projection): bool => in_array(
						$projection['semanticKey'] ?? null,
						$row['selectionKeys'],
						true,
					),
				];
			}
		}

		return null;
	}

	private function resolveOwnership(
		SafeTimelineDiscriminator $discriminator,
		?Context $context,
		?ItemIdentifier $owner,
	): string
	{
		$context ??= $this->activeContext;
		$owner ??= $this->activeOwner;
		$row = [
			'TYPE_ID' => $discriminator->get('TYPE_ID'),
			'TYPE_CATEGORY_ID' => $discriminator->get('TYPE_CATEGORY_ID'),
			'ACTIVITY_TYPE_ID' => $discriminator->get('ACTIVITY_TYPE_ID'),
			'PROVIDER_ID' => $discriminator->get('PROVIDER_ID'),
			'PROVIDER_TYPE_ID' => $discriminator->get('PROVIDER_TYPE_ID'),
		];
		$compiler = new PredicateCompiler($this, $context, $owner);
		foreach ($this->eligibleOwnershipDescriptors($compiler, $context, $owner) as $descriptor)
		{
			if ($compiler->matches($descriptor['exact'], $discriminator))
			{
				$legacyKey = $compiler->classify($row);
				if (in_array($legacyKey, $descriptor['reservedExact'], true))
				{
					return $legacyKey;
				}
				if ($legacyKey !== $descriptor['key'] && $compiler->isKnownSemanticKey($descriptor['key']))
				{
					return $legacyKey;
				}

				return $descriptor['key'];
			}
		}

		$key = $compiler->classify($row);
		if ($key !== 'other-event')
		{
			return $key;
		}

		$providerId = strtoupper((string)$row['PROVIDER_ID']);
		if ((int)$row['TYPE_ID'] === 1 && $providerId !== '')
		{
			try
			{
				$provider = ($this->providerRegistry ?? new ProviderRegistry())->get($providerId, true);
				if ($provider !== null && trim($provider->getName()) !== '')
				{
					return $this->aggregateKeyForProvider($providerId) ?? $this->providerRowKey($providerId);
				}
			}
			catch (\Throwable)
			{
				return 'other-event';
			}
		}

		return 'other-event';
	}

	private function eligibleOwnershipDescriptors(
		PredicateCompiler $compiler,
		?Context $context,
		?ItemIdentifier $owner,
	): array
	{
		$contextKey = $this->effectiveContextKey($context, $owner);
		if (isset($this->ownershipDescriptorsByContext[$contextKey]))
		{
			return $this->ownershipDescriptorsByContext[$contextKey];
		}
		$descriptors = [];
		foreach (TimelineEntryCategory::getDescriptors() as $descriptor)
		{
			$descriptors[] = [
				'key' => $descriptor->getId(),
				'exact' => $descriptor->getExact(),
				'reservedExact' => $descriptor->getReservedExact(),
			];
		}
		if ($context === null || $owner === null)
		{
			return $this->ownershipDescriptorsByContext[$contextKey] = $descriptors;
		}

		$registry = $this->providerRegistry ?? new ProviderRegistry();
		foreach ($registry->getAll(true) as $providerId => $providerDescriptor)
		{
			if ($providerDescriptor->isMembershipOnly())
			{
				continue;
			}
			try
			{
				$provider = $registry->get($providerId, true);
				if (!$provider instanceof ActivityProviderTypeCatalogInterface)
				{
					continue;
				}
				foreach ($provider->getTimelineTypeDescriptors() as $typeDescriptor)
				{
					if (!$provider->canExposeTimelineType($typeDescriptor->getId(), $context, $owner))
					{
						continue;
					}
					$descriptors[] = [
						'key' => $this->aggregateKeyForProvider($providerId)
							?? 'provider-type:' . strtolower($providerId) . ':' . $typeDescriptor->getId(),
						'exact' => $typeDescriptor->getExact(),
						'reservedExact' => [],
					];
				}
			}
			catch (\Throwable)
			{
				continue;
			}
		}

		return $this->ownershipDescriptorsByContext[$contextKey] = $descriptors;
	}

	private function semanticDescriptor(
		string $key,
		?Context $context = null,
		?ItemIdentifier $owner = null,
	): ?array
	{
		foreach ($this->semanticDescriptors($context, $owner) as $descriptor)
		{
			if ($descriptor['key'] === $key)
			{
				return $descriptor;
			}
		}

		return null;
	}

	private function semanticDescriptors(?Context $context = null, ?ItemIdentifier $owner = null): array
	{
		$contextKey = $this->contextKey($context, $owner);
		if ($context === null && $owner === null && $this->activeContextKey !== null)
		{
			$contextKey = $this->activeContextKey;
		}
		if (isset($this->semanticDescriptorsByContext[$contextKey]))
		{
			return $this->semanticDescriptorsByContext[$contextKey];
		}
		$rows = [];
		$public = [];
		foreach ($this->publicRows($context, $owner) as $row)
		{
			$public[$row['key']] = $row;
		}

		foreach (TimelineEntryCategory::getDescriptors() as $descriptor)
		{
			$key = $descriptor->getId();
			if (!isset($public[$key]))
			{
				continue;
			}
			$rows[$key] = $this->semanticRow(
				$public[$key],
				$descriptor->getCandidate(),
				$descriptor->getExact(),
				hash('sha256', Json::encode([
					$descriptor->getCandidate()->signature(),
					$descriptor->getExact()->signature(),
					$descriptor->getReservedExact(),
				])),
			);
		}

		if (isset($public['activity-bizproc-task']))
		{
			$expression = CandidateExpression::all(
				CandidateExpression::eq('TYPE_ID', 9),
				CandidateExpression::in('TYPE_CATEGORY_ID', [4, 5, 6]),
			);
			$rows['activity-bizproc-task'] = $this->semanticRow(
				$public['activity-bizproc-task'],
				$expression,
				$expression,
				'1',
			);
		}

		$registry = $this->providerRegistry ?? new ProviderRegistry();
		foreach ($registry->getAll(true) as $providerId => $providerDescriptor)
		{
			if ($providerDescriptor->isMembershipOnly())
			{
				continue;
			}
			try
			{
				$provider = $registry->get($providerId, true);
				if ($provider === null)
				{
					continue;
				}
				if ($provider instanceof ActivityProviderTypeCatalogInterface)
				{
					foreach ($provider->getTimelineTypeDescriptors() as $typeDescriptor)
					{
						if (
							$context === null
							|| $owner === null
							|| !$provider->canExposeTimelineType($typeDescriptor->getId(), $context, $owner)
						)
						{
							continue;
						}
						$key = $this->aggregateKeyForProvider($providerId)
							?? 'provider-type:' . strtolower($providerId) . ':' . $typeDescriptor->getId();
						if (!isset($public[$key]))
						{
							continue;
						}
						if (isset($rows[$key]))
						{
							$rows[$key]['candidate'] = CandidateExpression::any(
								$rows[$key]['candidate'],
								$typeDescriptor->getCandidate(),
							);
							$rows[$key]['exact'] = CandidateExpression::any(
								$rows[$key]['exact'],
								$typeDescriptor->getExact(),
							);
							$rows[$key]['descriptorVersion'] = hash('sha256', Json::encode([
								$rows[$key]['descriptorVersion'],
								$typeDescriptor->getVersion(),
							]));
						}
						else
						{
							$rows[$key] = $this->semanticRow(
								$public[$key],
								$typeDescriptor->getCandidate(),
								$typeDescriptor->getExact(),
								$typeDescriptor->getVersion(),
							);
						}
					}
				}

				$key = $this->aggregateKeyForProvider($providerId) ?? $this->providerRowKey($providerId);
				if (isset($public[$key]) && !isset($rows[$key]))
				{
					$expression = CandidateExpression::all(
						CandidateExpression::eq('TYPE_ID', 1),
						CandidateExpression::eq('PROVIDER_ID', $providerId),
					);
					$rows[$key] = $this->semanticRow($public[$key], $expression, $expression, '1');
				}
			}
			catch (\Throwable)
			{
				continue;
			}
		}

		foreach ($public as $key => $row)
		{
			if (isset($rows[$key]))
			{
				continue;
			}
			$expression = CandidateExpression::eq('FIXED_MARKER', $key);
			$rows[$key] = $this->semanticRow($row, $expression, $expression, '1');
		}

		$result = array_values($rows);
		$this->semanticDescriptorsByContext[$contextKey] = $result;
		if ($context !== null && $owner !== null)
		{
			$this->activeContextKey = $contextKey;
			$this->activeContext = $context;
			$this->activeOwner = $owner;
		}

		return $result;
	}

	private function semanticComposition(?Context $context, ?ItemIdentifier $owner): array
	{
		$composition = [];
		foreach ($this->semanticDescriptors($context, $owner) as $descriptor)
		{
			$composition[] = [
				'key' => $descriptor['key'],
				'title' => $descriptor['title'],
				'icon' => $descriptor['icon'],
				'order' => $descriptor['order'],
				'selectionKeys' => $descriptor['selectionKeys'],
				'sourceKind' => $descriptor['sourceKind'],
				'sourceId' => $descriptor['sourceId'],
				'descriptorVersion' => $descriptor['descriptorVersion'],
				'candidate' => $descriptor['candidate']->signature(),
				'exact' => $descriptor['exact']->signature(),
			];
		}

		$registry = $this->providerRegistry ?? new ProviderRegistry();
		foreach ($registry->getAll(true) as $providerId => $providerDescriptor)
		{
			$entry = [
				'providerId' => $providerId,
				'available' => $providerDescriptor->isAvailable(),
				'active' => null,
				'types' => [],
			];
			try
			{
				$provider = $registry->get($providerId, true);
				$entry['active'] = $provider?->isActive();
				if ($provider instanceof ActivityProviderTypeCatalogInterface)
				{
					foreach ($provider->getTimelineTypeDescriptors() as $typeDescriptor)
					{
						if (
							$context === null
							|| $owner === null
							|| !$provider->canExposeTimelineType($typeDescriptor->getId(), $context, $owner)
						)
						{
							continue;
						}
						$entry['types'][] = [
							'id' => $typeDescriptor->getId(),
							'version' => $typeDescriptor->getVersion(),
							'candidate' => $typeDescriptor->getCandidate()->signature(),
							'exact' => $typeDescriptor->getExact()->signature(),
						];
					}
				}
			}
			catch (\Throwable)
			{
				$entry['active'] = false;
			}
			$composition[] = $entry;
		}

		return $composition;
	}

	private function semanticRow(
		array $row,
		CandidateExpression $candidate,
		CandidateExpression $exact,
		string $descriptorVersion,
	): array
	{
		return $row + [
			'descriptorId' => $row['key'],
			'descriptorVersion' => $descriptorVersion,
			'candidate' => $candidate,
			'exact' => $exact,
		];
	}

	private function providerRowKey(string $providerId): string
	{
		return 'provider:' . strtolower(str_replace('_', '-', $providerId));
	}

	private function aggregateKeyForProvider(string $providerId): ?string
	{
		return match (strtoupper($providerId))
		{
			'CRM_TODO' => 'activity-todo',
			'CRM_MEETING', 'CRM_CALENDAR_SHARING', 'ZOOM' => 'activity-meeting',
			'VOXIMPLANT_CALL' => 'activity-call',
			'TASKS', 'CRM_TASKS_TASK', 'CRM_TASKS_TASK_COMMENT' => 'activity-task',
			'CRM_REQUEST', 'CRM_BIZPROC_TASK' => 'activity-bizproc-task',
			'CRM_BIZPROC_WORKFLOW', 'CRM_BIZPROC_COMMENT' => 'biz-process',
			'REST_APP', 'CONFIGURABLE_REST_APP' => 'application',
			'CRM_SIGN_DOCUMENT' => 'sign-document',
			'CRM_SIGN_B2E_DOCUMENT' => 'sign-b2e-document',
			'CRM_WAIT' => 'waiting',
			'CRM_DELIVERY' => 'delivery',
			'CRM_BOOKING' => 'booking',
			'CRM_WAIT_LIST_ITEM' => 'wait-list-item',
			default => null,
		};
	}

	private function contextKey(?Context $context, ?ItemIdentifier $owner): string
	{
		if ($context === null || $owner === null)
		{
			return 'contextless';
		}

		return implode(':', [
			$context->getUserId(),
			$owner->getEntityTypeId(),
			$owner->getEntityId(),
			$owner->getCategoryId() ?? 0,
		]);
	}

	private function effectiveContextKey(?Context $context, ?ItemIdentifier $owner): string
	{
		if ($context === null && $owner === null && $this->activeContextKey !== null)
		{
			return $this->activeContextKey;
		}

		return $this->contextKey($context, $owner);
	}

	private function row(
		string $key,
		string $title,
		?string $icon,
		int $order,
		array $selectionKeys,
		string $sourceKind,
		string $sourceId,
	): array
	{
		return compact('key', 'title', 'icon', 'order', 'selectionKeys') + [
			'identity' => null,
			'sourceKind' => $sourceKind,
			'sourceId' => $sourceId,
		];
	}

	private function iconFor(string $sourceKind, string $key): string
	{
		return match ($key)
		{
			'activity-call', 'provider:crm-call-tracker' => 'o-phone-up',
			'activity-visit', 'provider:visit-tracker' => 'user-profile',
			'activity-meeting' => 'o-meeting-point',
			'activity-email', 'provider:crm-email' => 'o-mail',
			'sms', 'provider:crm-sms' => 'o-sms',
			'whatsapp', 'provider:crm-whatsapp' => 'o-whatsapp',
			'telegram', 'provider:crm-telegram' => 'o-telegram',
			'chat', 'provider:imopenlines-session' => 'o-messages',
			'notification', 'provider:crm-notification' => 'o-new-message',
			'web-form', 'provider:crm-webform' => 'o-crm-form',
			'activity-task' => 'o-task',
			'activity-request' => 'o-empty-message',
			'biz-process' => 'o-busines-process-stages',
			'document' => 'o-file',
			'order' => 'o-package',
			'check' => 'receipt',
			'repeat-sale', 'provider:crm-repeat-sale' => 'o-repeat-sales',
			'application' => 'o-apps',
			default => $sourceKind === 'provider' ? 'o-circle-check' : 'o-message',
		};
	}
}
