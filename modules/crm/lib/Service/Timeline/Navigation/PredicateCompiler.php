<?php

namespace Bitrix\Crm\Service\Timeline\Navigation;

use Bitrix\Crm\Filter\TimelineEntryCategory;
use Bitrix\Crm\Service\Timeline\Item;
use Bitrix\Crm\Service\Timeline\Item\Compatible\Model as CompatibleModel;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Context;
use Bitrix\Crm\Timeline\Bizproc\CategoryType as BizprocCategoryType;
use Bitrix\Crm\Timeline\LogMessageType;
use Bitrix\Crm\Timeline\TimelineType;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;

final class PredicateCompiler
{
	private const TYPE_MODE_ONLY = 'only';
	private const TYPE_MODE_EXCEPT = 'except';
	private const TYPE_KEYS = [
		2 => 'creation', 3 => 'modification', 4 => 'link', 5 => 'unlink', 6 => 'mark', 7 => 'comment',
		8 => 'waiting', 9 => 'biz-process', 10 => 'conversion', 11 => 'sender', 12 => 'document',
		13 => 'restoration', 14 => 'order', 15 => 'check', 16 => 'scoring', 17 => 'external-notice',
		18 => 'final-summary', 19 => 'delivery', 20 => 'final-summary', 21 => 'store-document',
		22 => 'product-compilation', 23 => 'sign-document', 24 => 'sign-document', 25 => 'log-message',
		26 => 'activity-calendar-sharing', 27 => 'activity-task', 28 => 'ai-processing',
		29 => 'sign-b2e-document', 30 => 'sign-b2e-document', 31 => 'booking', 32 => 'wait-list-item',
	];
	private const PROVIDER_KEYS = [
		'CRM_TODO' => 'activity-todo', 'VOXIMPLANT_CALL' => 'activity-call',
		'CRM_CALL_TRACKER' => 'activity-call-tracker', 'VISIT_TRACKER' => 'activity-visit',
		'CRM_MEETING' => 'activity-meeting', 'CRM_CALENDAR_SHARING' => 'activity-calendar-sharing',
		'ZOOM' => 'activity-zoom', 'CRM_EMAIL' => 'activity-email', 'TASKS' => 'activity-task',
		'CRM_TASKS_TASK' => 'activity-task', 'CRM_TASKS_TASK_COMMENT' => 'activity-task',
		'CRM_REQUEST' => 'activity-bizproc-task', 'CRM_BIZPROC_TASK' => 'activity-bizproc-task',
		'CRM_BIZPROC_WORKFLOW' => 'biz-process', 'CRM_BIZPROC_COMMENT' => 'biz-process',
		'CRM_SMS' => 'sms', 'CRM_WHATSAPP' => 'whatsapp', 'CRM_TELEGRAM' => 'telegram',
		'CRM_NOTIFICATION' => 'notification', 'CRM_WEBFORM' => 'web-form',
		'IMOPENLINES_SESSION' => 'chat', 'CRM_DOCUMENT' => 'document',
		'CRM_SIGN_DOCUMENT' => 'sign-document', 'CRM_SIGN_B2E_DOCUMENT' => 'sign-b2e-document',
		'CRM_WAIT' => 'waiting', 'CRM_ENTITY_EXCLUSION' => 'entity-exclusion',
		'CRM_REPEAT_SALE' => 'repeat-sale', 'REST_APP' => 'application',
		'CONFIGURABLE_REST_APP' => 'application', 'CRM_PAYMENT' => 'activity-payment',
		'CRM_DELIVERY' => 'delivery', 'STORE_DOCUMENT' => 'store-document',
		'CRM_BOOKING' => 'booking', 'CRM_WAIT_LIST_ITEM' => 'wait-list-item', 'CRM_SYNC' => 'activity-sync',
	];
	private const CATEGORY_KEYS = [
		TimelineType::BIZPROC => [
			BizprocCategoryType::TASK_ADDED => 'activity-bizproc-task',
			BizprocCategoryType::TASK_COMPLETED => 'activity-bizproc-task',
			BizprocCategoryType::TASK_DELEGATED => 'activity-bizproc-task',
		],
	];
	private const TECHNICAL_TYPES = [2, 3, 4, 5, 10, 13];
	private const TECHNICAL_LOG = [
		1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12,
		13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24,
		25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36,
	];
	private const TECHNICAL_TASK = [0, 1, 2, 3, 4, 5, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16];
	private const TECHNICAL_BIZPROC = [4, 5, 6, 7, 8];

	public function __construct(
		private readonly ?TypeCatalog $catalog = null,
		private readonly ?Context $context = null,
		private readonly ?ItemIdentifier $owner = null,
	)
	{
	}

	public function compile(CandidateExpression $expression): ConditionTree
	{
		return $this->compileQuerySafe($expression) ?? new ConditionTree();
	}

	private function compileQuerySafe(CandidateExpression $expression): ?ConditionTree
	{
		$condition = new ConditionTree();
		$operator = $expression->getOperator();
		if ($operator === CandidateExpression::ALL)
		{
			$condition->logic('and');
			foreach ($expression->getOperands() as $operand)
			{
				$compiled = $this->compileQuerySafe($operand);
				if ($compiled !== null)
				{
					$condition->where($compiled);
				}
			}

			return $condition->hasConditions() ? $condition : null;
		}
		if ($operator === CandidateExpression::ANY)
		{
			$condition->logic('or');
			foreach ($expression->getOperands() as $operand)
			{
				$compiled = $this->compileQuerySafe($operand);
				if ($compiled === null)
				{
					return null;
				}
				$condition->where($compiled);
			}

			return $condition;
		}

		$field = match ($expression->getField())
		{
			'PROVIDER_ID' => 'ASSOCIATED_ENTITY_CLASS_NAME',
			'TYPE_ID',
			'TYPE_CATEGORY_ID',
			'ASSOCIATED_ENTITY_TYPE_ID',
			'ASSOCIATED_ENTITY_CLASS_NAME' => (string)$expression->getField(),
			default => null,
		};
		if ($field === null)
		{
			return null;
		}
		if ($operator === CandidateExpression::EQ)
		{
			return $condition->where($field, $expression->getValue());
		}
		if ($operator === CandidateExpression::IN)
		{
			return $condition->whereIn($field, $expression->getValue());
		}

		return $condition->whereNull($field);
	}

	public function matches(
		CandidateExpression $expression,
		SafeTimelineDiscriminator $discriminator,
	): bool
	{
		$operator = $expression->getOperator();
		if ($operator === CandidateExpression::ALL)
		{
			foreach ($expression->getOperands() as $operand)
			{
				if (!$this->matches($operand, $discriminator))
				{
					return false;
				}
			}

			return true;
		}
		if ($operator === CandidateExpression::ANY)
		{
			foreach ($expression->getOperands() as $operand)
			{
				if ($this->matches($operand, $discriminator))
				{
					return true;
				}
			}

			return false;
		}

		$value = $discriminator->get((string)$expression->getField());

		return match ($operator)
		{
			CandidateExpression::EQ => $value === $expression->getValue(),
			CandidateExpression::IN => in_array($value, $expression->getValue(), true),
			CandidateExpression::IS_NULL => $value === null,
			default => false,
		};
	}

	public function semanticPredicate(string $key): array
	{
		$catalog = $this->catalog ?? new TypeCatalog();
		$selectionKeys = $catalog->selectionKeys($key, $this->context, $this->owner);
		if ($selectionKeys === [])
		{
			return ['key' => $key, 'superset' => false, 'typeIds' => []];
		}
		$typeIds = [];
		foreach ($selectionKeys as $selectionKey)
		{
			$typeIds = [...$typeIds, ...$this->typeIdsFor($selectionKey)];
		}
		$typeIds = array_values(array_unique($typeIds));

		return [
			'key' => $key,
			'superset' => $key === 'other-event' || $typeIds === [],
			'typeIds' => $typeIds,
		];
	}

	private function typeIdsFor(string $key): array
	{
		$typeIds = array_keys(self::TYPE_KEYS, $key, true);
		if (in_array($key, self::PROVIDER_KEYS, true))
		{
			$typeIds[] = 1;
		}
		foreach (self::CATEGORY_KEYS as $typeId => $categories)
		{
			if (in_array($key, $categories, true))
			{
				$typeIds[] = $typeId;
			}
		}

		return $typeIds;
	}

	public function candidateCondition(array $crm): ConditionTree
	{
		$condition = new ConditionTree();
		$typeKeys = array_values(array_diff($crm['typeKeys'], $crm['staleTypeKeys'] ?? []));
		if (($crm['typeMode'] ?? self::TYPE_MODE_ONLY) === self::TYPE_MODE_EXCEPT)
		{
			return $condition;
		}
		if ($crm['typeKeys'] !== [] && $typeKeys === [])
		{
			return $condition->where('ID', -1);
		}
		if ($typeKeys === [])
		{
			return $condition;
		}

		$hasTechnical = in_array('technical', $typeKeys, true);
		$typeKeys = array_values(array_filter($typeKeys, static fn(string $key): bool => $key !== 'technical'));
		$typeIds = [];
		$expressions = [];
		$unrestricted = false;
		$catalog = $this->catalog ?? new TypeCatalog();
		foreach ($typeKeys as $key)
		{
			$predicate = $this->semanticPredicate($key);
			$typeIds = array_merge($typeIds, $predicate['typeIds']);
			$expression = $catalog->candidateExpression($key, $this->context, $this->owner);
			if ($predicate['typeIds'] === [] && $expression !== null && $key !== 'other-event')
			{
				$compiled = $this->compileQuerySafe($expression);
				if ($compiled === null)
				{
					$unrestricted = true;
				}
				else
				{
					$expressions[] = $compiled;
				}
			}
			$unrestricted = $unrestricted || $key === 'other-event';
		}
		if ($unrestricted)
		{
			return $condition;
		}

		$union = (new ConditionTree())->logic('or');
		$typeIds = array_values(array_unique($typeIds));
		sort($typeIds, SORT_NUMERIC);
		if ($typeIds !== [])
		{
			$union->whereIn('TYPE_ID', $typeIds);
		}
		foreach ($expressions as $expression)
		{
			$union->where($expression);
		}
		if ($hasTechnical)
		{
			$union->where($this->technicalCondition());
		}
		if (!$union->hasConditions())
		{
			return $condition->where('ID', -1);
		}

		return $condition->where($union);
	}

	public function matchesProjectedItem(Item $item, array $crm, array $rawPosition): bool
	{
		$typeKeys = array_values(array_diff($crm['typeKeys'], $crm['staleTypeKeys'] ?? []));
		if ($crm['typeKeys'] === [])
		{
			return true;
		}

		$description = $this->describeProjectedItem($item, $rawPosition);
		$selectedKeys = [];
		foreach (array_filter($typeKeys, static fn(string $key): bool => $key !== 'technical') as $key)
		{
			$selectionKeys = ($this->catalog ?? new TypeCatalog())->selectionKeys(
				$key,
				$this->context,
				$this->owner,
			);
			if ($selectionKeys !== [])
			{
				$selectedKeys = [...$selectedKeys, ...$selectionKeys];
			}
		}
		$matchesType = in_array($description['semanticKey'], $selectedKeys, true)
			|| (in_array('technical', $typeKeys, true) && $description['isTechnical']);

		return ($crm['typeMode'] ?? self::TYPE_MODE_ONLY) === self::TYPE_MODE_EXCEPT
			? !$matchesType
			: $matchesType;
	}

	public function describeProjectedItem(Item $item, array $rawPosition): array
	{
		$model = $item->getModel();
		if ($model instanceof CompatibleModel)
		{
			$row = $model->getData();
			$associated = $row['ASSOCIATED_ENTITY'] ?? [];
		}
		else
		{
			$associated = $model->getAssociatedEntityModel()?->toArray() ?? [];
			$row = [
				'TYPE_ID' => $model->getTypeId(),
				'TYPE_CATEGORY_ID' => array_key_exists('categoryId', $rawPosition)
					? $rawPosition['categoryId'] : $model->getTypeCategoryId(),
			];
		}
		$row['ACTIVITY_TYPE_ID'] = $associated['TYPE_ID'] ?? 0;
		$row['PROVIDER_ID'] = $associated['PROVIDER_ID'] ?? $rawPosition['className'] ?? '';
		$row['PROVIDER_TYPE_ID'] = $associated['PROVIDER_TYPE_ID'] ?? null;

		return [
			'semanticKey' => ($this->catalog ?? new TypeCatalog())->classify(
				$row,
				$this->context,
				$this->owner,
			),
			'isTechnical' => $this->isTechnical($row),
		];
	}

	public function technicalPredicate(): array
	{
		return [
			['TYPE_ID' => self::TECHNICAL_TYPES],
			['TYPE_ID' => 25, 'TYPE_CATEGORY_ID' => self::TECHNICAL_LOG],
			['TYPE_ID' => 27, 'TYPE_CATEGORY_ID' => self::TECHNICAL_TASK],
			['TYPE_ID' => 9, 'TYPE_CATEGORY_ID' => self::TECHNICAL_BIZPROC],
		];
	}

	public function isTechnical(array $row): bool
	{
		$type = (int)($row['TYPE_ID'] ?? 0);
		$category = $row['TYPE_CATEGORY_ID'] ?? null;
		if (in_array($type, self::TECHNICAL_TYPES, true))
		{
			return true;
		}
		if ($category === null)
		{
			return false;
		}
		$category = (int)$category;

		return ($type === 25 && in_array($category, self::TECHNICAL_LOG, true))
			|| ($type === 27 && in_array($category, self::TECHNICAL_TASK, true))
			|| ($type === 9 && in_array($category, self::TECHNICAL_BIZPROC, true));
	}

	public function classify(array $row): string
	{
		$legacyKey = $this->classifyLegacy($row);
		$discriminator = SafeTimelineDiscriminator::fromStoredFields($row);
		foreach (TimelineEntryCategory::getDescriptors() as $descriptor)
		{
			if (!$this->matches($descriptor->getExact(), $discriminator))
			{
				continue;
			}
			if (in_array($legacyKey, $descriptor->getReservedExact(), true))
			{
				return $legacyKey;
			}
			if ($legacyKey !== $descriptor->getId() && $this->isKnownSemanticKey($descriptor->getId()))
			{
				return $legacyKey;
			}

			return $descriptor->getId();
		}

		return $legacyKey;
	}

	private function classifyLegacy(array $row): string
	{
		$type = (int)($row['TYPE_ID'] ?? 0);
		$provider = strtoupper((string)($row['PROVIDER_ID'] ?? $row['ASSOCIATED_ENTITY_CLASS_NAME'] ?? ''));
		$activityType = (int)($row['ACTIVITY_TYPE_ID'] ?? 0);
		if ($type === 1)
		{
			if ($activityType === 2) { return 'activity-call'; }
			if ($activityType === 1) { return 'activity-meeting'; }
			if ($activityType === 4) { return 'activity-email'; }
			if ($activityType === 3) { return 'activity-task'; }

			return $this->classifyProvider($provider);
		}
		$category = $row['TYPE_CATEGORY_ID'] ?? null;
		if ($category !== null && isset(self::CATEGORY_KEYS[$type][(int)$category]))
		{
			return self::CATEGORY_KEYS[$type][(int)$category];
		}
		if ($type === TimelineType::LOG_MESSAGE)
		{
			static $knownCategories = null;
			$knownCategories ??= array_flip((new \ReflectionClass(LogMessageType::class))->getConstants());
			if (!isset($knownCategories[(int)($row['TYPE_CATEGORY_ID'] ?? LogMessageType::UNDEFINED)]))
			{
				return 'other-event';
			}
		}

		return self::TYPE_KEYS[$type] ?? 'other-event';
	}

	public function isKnownSemanticKey(string $key): bool
	{
		if (in_array($key, self::TYPE_KEYS, true) || in_array($key, self::PROVIDER_KEYS, true))
		{
			return true;
		}
		foreach (self::CATEGORY_KEYS as $categories)
		{
			if (in_array($key, $categories, true))
			{
				return true;
			}
		}

		return false;
	}

	private function technicalCondition(): ConditionTree
	{
		$technical = (new ConditionTree())->logic('or');
		foreach ($this->technicalPredicate() as $predicate)
		{
			$branch = new ConditionTree();
			foreach ($predicate as $field => $value)
			{
				$branch->whereNotNull($field);
				if (is_array($value))
				{
					$branch->whereIn($field, $value);
				}
				else
				{
					$branch->where($field, $value);
				}
			}
			$technical->where($branch);
		}

		return $technical;
	}

	private function classifyProvider(string $provider): string
	{
		return self::PROVIDER_KEYS[$provider] ?? 'other-event';
	}
}
