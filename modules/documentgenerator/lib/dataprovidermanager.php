<?php

namespace Bitrix\DocumentGenerator;

use Bitrix\DocumentGenerator\DataProvider\ArrayDataProvider;
use Bitrix\DocumentGenerator\DataProvider\HashDataProvider;
use Bitrix\DocumentGenerator\DataProvider\Rest;
use Bitrix\DocumentGenerator\DataProvider\User;
use Bitrix\DocumentGenerator\Feature\IdCollections;
use Bitrix\DocumentGenerator\Model\RegionPhraseTable;
use Bitrix\DocumentGenerator\Value\DateTime;
use Bitrix\DocumentGenerator\Value\Multiple;
use Bitrix\DocumentGenerator\Value\Name;
use Bitrix\DocumentGenerator\Value\PhoneNumber;
use Bitrix\Main\Context\Culture;
use Bitrix\Main\Config\Feature;
use Bitrix\DocumentGenerator\Retention\PressurePolicy;
use Bitrix\DocumentGenerator\Retention\MemoryLimitPressurePolicy;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\EventResult;
use Bitrix\Main\IO\File;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Type\Date;

class DataProviderManager
{
	public const MAX_DEPTH_LEVEL_ROOT_PROVIDERS = 2;

	protected array $providersCache = [];
	protected array $accessCache = [];
	protected array $phrases = [];
	protected array $loadedPhrasePath = [];
	protected array $substitutionProviders = [];

	/**
	 * @deprecated
	 * Use the methods instead
	 * @see self::getContext()
	 * @see self::setContext()
	 */
	protected Context $context;
	protected EventManager $eventManager;

	/** @var \SplStack<Context> */
	private \SplStack $contextStack;
	private ?bool $isIdCollectionsEnabled = null;
	private array $itemIdMethods = [];
	private array $layerTicks = [];
	private array $layerRecords = [];
	private array $layerAccessByKey = [];
	private int $layerTick = 0;
	private ?\WeakMap $layerKeys = null;
	private ?\WeakMap $layerChildren = null;
	private ?\WeakMap $layerAccess = null;
	private ?\WeakMap $collectionItems = null;
	private ?PressurePolicy $pressurePolicy = null;
	private ?array $expectedCollectionItem = null;

	public function __construct()
	{
		$this->eventManager = EventManager::getInstance();

		$this->contextStack = new \SplStack();
		$this->contextStack->push(new Context());
		$this->context = $this->contextStack->top();

		$this->fillSubstitutionProviders();
	}

	/**
	 * @return DataProviderManager
	 */
	public static function getInstance(): DataProviderManager
	{
		return Driver::getInstance()->getDataProviderManager();
	}

	/**
	 * @param Context $context
	 * @return DataProviderManager
	 */
	public function setContext(Context $context): DataProviderManager
	{
		$this->contextStack->push($context);
		$this->context = $context;

		return $this;
	}

	/**
	 * Resets global context to its previous value (before the last self::setContext call)
	 */
	final public function resetContext(): self
	{
		// don't allow resetting root context that was created on manager instantiation
		if ($this->contextStack->count() > 1)
		{
			$this->contextStack->pop();
			$this->context = $this->contextStack->top();
		}

		return $this;
	}

	/**
	 * @return Context
	 */
	public function getContext(): Context
	{
		return $this->contextStack->top();
	}

	/**
	 * Returns true if $providerClassName is a valid DataProvider.
	 * Module with this class should be included before this check.
	 *
	 * @param string $providerClassName
	 * @param string $moduleId
	 * @return bool
	 */
	public static function checkProviderName($providerClassName, $moduleId = null): bool
	{
		$result = is_a($providerClassName, DataProvider::class, true);

		$documentProviders = [
			mb_strtolower(ArrayDataProvider::class),
			mb_strtolower(User::class),
		];
		if(in_array(mb_strtolower($providerClassName), $documentProviders, true))
		{
			return true;
		}
		if($moduleId && is_string($moduleId) && !empty($moduleId))
		{
			$result = false;
			$providers = static::getInstance()->getList(['filter' => ['MODULE' => $moduleId]]);
			$providerClassName = mb_strtolower($providerClassName);
			if (!is_a($providerClassName, DataProvider::class, true))
			{
				return false;
			}
			foreach($providers as $name => $provider)
			{
				if(
					$name === $providerClassName
					|| (
						isset($provider['ORIGINAL'])
						&& $provider['ORIGINAL'] === $providerClassName
					)
				)
				{
					return true;
				}
			}
		}

		return $result;
	}

	protected function fillSubstitutionProviders(): void
	{
		$event = new Event(Driver::MODULE_ID, 'onDataProviderManagerFillSubstitutionProviders');
		$providers = [];
		$this->eventManager->send($event);
		foreach($event->getResults() as $result)
		{
			if($result->getType() === EventResult::SUCCESS && is_array($result->getParameters()))
			{
				/** @noinspection SlowArrayOperationsInLoopInspection */
				$providers = array_merge($providers, $result->getParameters());
			}
		}

		$this->setSubstitutionProviders($providers);
	}

	public function setSubstitutionProviders(array $substitutionProviders): DataProviderManager
	{
		$this->substitutionProviders = $substitutionProviders;

		return $this;
	}

	protected function getSubstitutionProvider(string $provider): ?string
	{
		foreach($this->substitutionProviders as $originalProvider => $substitutionProvider)
		{
			if(
				strtolower($provider) === strtolower($originalProvider)
				&& is_a($substitutionProvider, $provider, true)
			)
			{
				return $substitutionProvider;
			}
		}

		return null;
	}

	/**
	 * Resolve and executes callback from VALUE in field with $placeholder
	 *
	 * @param DataProvider $dataProvider
	 * @param string|int $placeholder
	 * @return DataProvider|false|mixed
	 */
	public function getDataProviderValue(DataProvider $dataProvider, $placeholder)
	{
		if($placeholder !== 0 && empty($placeholder))
		{
			return false;
		}

		if(!$dataProvider->isLoaded())
		{
			return false;
		}

		$placeholderParts = explode('.', $placeholder);
		if(count($placeholderParts) > 1)
		{
			$provider = $this->getDataProviderValue($dataProvider, $placeholderParts[0]);
			if($provider && $provider instanceof DataProvider)
			{
				$value = $provider->getValue(implode('.', array_slice($placeholderParts, 1)));
				if (
					$this->getContext()->getIsCheckAccess()
					&& !$this->checkDataProviderAccess($provider, $this->getContext()->getUserId())
				)
				{
					$value = false;
				}
			}
			else
			{
				$value = false;
			}
		}
		else
		{
			$value = $this->calculateDataProviderValue($dataProvider, $placeholder);
		}

		return $value;
	}

	/**
	 * @param DataProvider $dataProvider
	 * @param $placeholder
	 * @return DataProvider|false|mixed
	 */
	protected function calculateDataProviderValue(DataProvider $dataProvider, $placeholder)
	{
		$fields = $dataProvider->getFields();
		if(!isset($fields[$placeholder]))
		{
			return false;
		}

		$fieldDescription = $fields[$placeholder];

		// rewrite inner values from options.
		$value = false;
		$values = [];
		if(isset($fieldDescription['OPTIONS']['VALUES']) && is_array($fieldDescription['OPTIONS']['VALUES']))
		{
			$values = $fieldDescription['OPTIONS']['VALUES'];
		}
		$options = $dataProvider->getOptions();
		if(
			isset($options['VALUES']) &&
			is_array($options['VALUES'])
		)
		{
			$values = array_merge($values, $options['VALUES']);
		}

		if(isset($values[$placeholder]))
		{
			$value = $values[$placeholder];
		}

		$ids = $this->resolveItemIds($fieldDescription, $dataProvider, $placeholder);
		if ($ids !== null)
		{
			return $ids ? $this->createIdCollection($fieldDescription, $ids, $dataProvider, $placeholder) : [];
		}

		$calculatedValue = false;
		if(isset($fieldDescription['VALUE']))
		{
			$calculatedValue = $this->getValue($fieldDescription['VALUE'], $dataProvider, $placeholder);
		}
		if(is_array($calculatedValue) && count($calculatedValue) > 0 && is_array(reset($calculatedValue)))
		{
			$selectedFound = false;
			if($value)
			{
				foreach($calculatedValue as &$calcVal)
				{
					if($calcVal['VALUE'] != $value)
					{
						$calcVal['SELECTED'] = false;
					}
					else
					{
						$calcVal['SELECTED'] = true;
						$selectedFound = true;
					}
				}
				unset($calcVal);
			}
			if(!$selectedFound)
			{
				foreach($calculatedValue as &$calcVal)
				{
					if(isset($calcVal['SELECTED']) && $calcVal['SELECTED'] === true)
					{
						$selectedFound = true;
						break;
					}
				}
				unset($calcVal);
			}
			if(!$selectedFound)
			{
				foreach($calculatedValue as &$calcVal)
				{
					$calcVal['SELECTED'] = true;
					break;
				}
				unset($calcVal);
			}
			$value = $calculatedValue;
		}

		if(!$value)
		{
			$value = $this->getValueFromList($calculatedValue);
		}
		else
		{
			$value = $calculatedValue;
		}

		if($value && isset($fieldDescription['PROVIDER']))
		{
			// if $value is array and Provider does not accept array as value - returns $values as it is - to allow user decide
			// which value to use.
			if(is_array($value) && !$this->isProviderArray($fieldDescription['PROVIDER']))
			{
				return $value;
			}
			$value = $this->createDataProvider($fieldDescription, $value, $dataProvider, $placeholder);
		}

		return $value;
	}

	/**
	 * Try to create new DataProvider instance from $fieldDescription on $value.
	 *
	 * @param array $fieldDescription
	 * @param mixed $value
	 * @param DataProvider|null $parentDataProvider
	 * @param string $placeholder
	 * @return DataProvider|null
	 */
	public function createDataProvider(
		array $fieldDescription,
		$value = null,
		DataProvider $parentDataProvider = null,
		$placeholder = null
	): ?DataProvider
	{
		if(!$value && isset($fieldDescription['VALUE']))
		{
			$value = $this->getValue($fieldDescription['VALUE'], $parentDataProvider, $placeholder);
		}

		if(!$value)
		{
			return null;
		}

		if($value instanceof Value)
		{
			$value = $value->getValue();
		}

		if(isset($fieldDescription['PROVIDER']))
		{
			$options = $fieldDescription['OPTIONS'] ?? [];
			if(!isset($options['VALUES']))
			{
				$options['VALUES'] = [];
			}
			// rewrite values of inner provider from parent options
			if($parentDataProvider)
			{
				$parentProviderOptions = $parentDataProvider->getOptions();
				if(isset($parentProviderOptions['VALUES']) && is_array($parentProviderOptions['VALUES']) && $placeholder !== null)
				{
					$options['VALUES'] = array_merge($options['VALUES'], $this->reformOptionValues($parentProviderOptions['VALUES'], $placeholder));
				}
			}

			return $this->getDataProvider($fieldDescription['PROVIDER'], $value, $options, $parentDataProvider);
		}

		return null;
	}

	/**
	 * @param mixed $value
	 * @param array $fieldDescription
	 * @return Value|mixed
	 */
	public function prepareValue($value, $fieldDescription = [])
	{
		if($value instanceof Value)
		{
			return $value;
		}

		if(isset($fieldDescription['PROVIDER']) && !empty($fieldDescription['PROVIDER']))
		{
			return $value;
		}

		$type = null;
		$options = [];
		if(is_array($fieldDescription) && array_key_exists('TYPE', $fieldDescription) && !empty($fieldDescription['TYPE']))
		{
			$type = $fieldDescription['TYPE'];
		}
		if(isset($fieldDescription['FORMAT']))
		{
			$options = $fieldDescription['FORMAT'];
		}

		if($type !== DataProvider::FIELD_TYPE_NAME && $this->isMultiple($value))
		{
			$result = [];
			foreach($value as $singleValue)
			{
				if(!empty($singleValue))
				{
					$result[] = $this->getValueByType($singleValue, $type, $options);
				}
			}
			if(!empty($result))
			{
				// no need for Multiple if there is only one item.
				if(!($result[0] instanceof DateTime) && count($result) === 1)
				{
					return reset($result);
				}
				return new Multiple($result, $options);
			}

			return null;
		}

		return $this->getValueByType($value, $type, $options);
	}

	/**
	 * @param $value
	 * @param $type
	 * @param $options
	 * @return Value
	 */
	protected function getValueByType($value, $type, $options)
	{
		if(empty($value) && !is_numeric($value))
		{
			return $value;
		}
		if($value instanceof Value)
		{
			return $value;
		}
		if($type === DataProvider::FIELD_TYPE_DATE || $value instanceof Date)
		{
			$value = new DateTime($value, $options);
		}
		elseif($type === DataProvider::FIELD_TYPE_NAME && is_array($value))
		{
			$value = new Name($value, $options);
		}
		elseif($type === DataProvider::FIELD_TYPE_PHONE)
		{
			$value = new PhoneNumber($value, $options);
		}
		elseif(is_a($type, Value::class, true))
		{
			$value = new $type($value, $options);
		}

		return $value;
	}

	/**
	 * Invoke callback to get value.
	 *
	 * @param $valueDescription
	 * @param DataProvider|null $parentDataProvider
	 * @param null $placeholder
	 * @return false|mixed
	 */
	protected function getValue($valueDescription, DataProvider $parentDataProvider = null, $placeholder = null)
	{
		$value = false;
		if($parentDataProvider && is_string($valueDescription) && $placeholder !== $valueDescription)
		{
			$value = $parentDataProvider->getValue($valueDescription);
		}
		elseif(is_callable($valueDescription))
		{
			if (
				(
					is_array($valueDescription)
					&& (
						is_a($valueDescription[0], DataProvider::class, true)
						|| is_object($valueDescription[0])
					)
				)
				|| $valueDescription instanceof \Closure
			)
			{
				$value = call_user_func($valueDescription, $placeholder);
			}
		}

		return $value;
	}

	/**
	 * Creates new DataProvider on $value with $options.
	 * If DataProvider with the same $value, $options and class exists in cache - returns it.
	 *
	 * @param string $providerClassName
	 * @param mixed $value
	 * @param array $options
	 * @param DataProvider $parentDataProvider
	 * @return DataProvider|null
	 */
	public function getDataProvider(
		$providerClassName,
		$value,
		array $options = [],
		DataProvider $parentDataProvider = null
	): ?DataProvider
	{
		$expected = $this->expectedCollectionItem;
		$isCollectionItem = $expected !== null && $expected[0] === $providerClassName && $expected[1] === $value;
		$valueHash = $isCollectionItem ? $expected[2] : $this->getValueHash($value, $options);
		if($this->isProviderArray($providerClassName))
		{
			$valueHash .= $parentDataProvider ? '|p' . spl_object_hash($parentDataProvider) : '|n';
		}
		if($this->isIdCollectionsEnabled())
		{
			$isNew = !isset($this->providersCache[$providerClassName][$valueHash]);
			if(!isset($this->providersCache[$providerClassName][$valueHash]))
			{
				$provider = null;
				if(self::checkProviderName($providerClassName))
				{
					$providerClassToInstantiate = $providerClassName;
					if(!isset($options['noSubstitution']) || $options['noSubstitution'] !== true)
					{
						$substitutionProvider = $this->getSubstitutionProvider($providerClassName);
						if($substitutionProvider)
						{
							$providerClassToInstantiate = $substitutionProvider;
						}
					}
					/** @var DataProvider $provider */
					$provider = new $providerClassToInstantiate($value, $options);
					if($parentDataProvider)
					{
						$provider->setParentProvider($parentDataProvider);
					}
				}

				$this->providersCache[$providerClassName][$valueHash] = $provider;
			}

			$provider = $this->providersCache[$providerClassName][$valueHash];
			if ($provider)
			{
				$this->trackLayerProvider(
					$provider, $providerClassName, $valueHash, $parentDataProvider, $isCollectionItem, $isNew,
				);
			}

			return $provider;
		}

		if(!isset($this->providersCache[$providerClassName][$valueHash]))
		{
			$provider = null;
			if(self::checkProviderName($providerClassName))
			{
				if(!isset($options['noSubstitution']) || $options['noSubstitution'] !== true)
				{
					$substitutionProvider = $this->getSubstitutionProvider($providerClassName);
					if($substitutionProvider)
					{
						$providerClassName = $substitutionProvider;
					}
				}
				/** @var DataProvider $provider */
				$provider = new $providerClassName($value, $options);
				if($parentDataProvider)
				{
					$provider->setParentProvider($parentDataProvider);
				}
			}

			$this->providersCache[$providerClassName][$valueHash] = $provider;
		}

		return $this->providersCache[$providerClassName][$valueHash];
	}

	private function resolveItemIds(array $field, DataProvider $owner, $placeholder): ?array
	{
		if (!$this->isIdCollectionsEnabled() || !is_string($field['ITEM_IDS'] ?? null)
			|| !is_string($field['PROVIDER'] ?? null))
		{
			return null;
		}
		$class = $field['PROVIDER'];
		if (($field['OPTIONS']['noSubstitution'] ?? false) !== true)
		{
			$class = $this->getSubstitutionProvider($class) ?? $class;
		}
		if (strcasecmp($class, ArrayDataProvider::class) !== 0)
		{
			return null;
		}
		$method = $field['ITEM_IDS'];
		$key = get_class($owner) . '::' . $method;
		if (!array_key_exists($key, $this->itemIdMethods))
		{
			$valid = false;
			if (method_exists($owner, $method))
			{
				$reflection = new \ReflectionMethod($owner, $method);
				$valid = $reflection->isPublic() && !$reflection->isStatic();
			}
			$this->itemIdMethods[$key] = $valid;
		}
		if (!$this->itemIdMethods[$key])
		{
			return null;
		}
		$ids = $owner->$method($placeholder);
		if (!is_array($ids) || !array_is_list($ids))
		{
			return null;
		}
		foreach ($ids as $id)
		{
			if (!is_int($id) || $id <= 0)
			{
				return null;
			}
		}

		return $ids;
	}

	private function createIdCollection(array $field, array $ids, DataProvider $owner, $placeholder): ArrayDataProvider
	{
		$options = $field['OPTIONS'] ?? [];
		$options['VALUES'] ??= [];
		$parentOptions = $owner->getOptions();
		if (isset($parentOptions['VALUES']) && is_array($parentOptions['VALUES']) && $placeholder !== null)
		{
			$options['VALUES'] = array_merge(
				$options['VALUES'], $this->reformOptionValues($parentOptions['VALUES'], $placeholder),
			);
		}
		$key = 'ids|' . $this->getValueHash($ids, $options)
			. $this->getValueHash($owner->getSource(), ['class' => get_class($owner)])
			. '|p' . spl_object_hash($owner);
		$isNew = !isset($this->providersCache[ArrayDataProvider::class][$key]);
		if ($isNew)
		{
			$collection = new ArrayDataProvider($ids, $options);
			$collection->setParentProvider($owner);
			$collection->enableItemIds($this);
			$this->providersCache[ArrayDataProvider::class][$key] = $collection;
			$this->collectionItems ??= new \WeakMap();
			$itemOptions = $options['ITEM_OPTIONS'] ?? [];
			$this->collectionItems[$collection] = [
				$options['ITEM_PROVIDER'], $itemOptions, $this->getValueHash('', $itemOptions),
			];
		}

		$collection = $this->providersCache[ArrayDataProvider::class][$key];
		$this->trackLayerProvider($collection, ArrayDataProvider::class, $key, $owner, false, $isNew);

		return $collection;
	}

	/** @internal */
	public function getCollectionItem(ArrayDataProvider $collection, int $id): ?DataProvider
	{
		$this->collectionItems ??= new \WeakMap();
		if (!isset($this->collectionItems[$collection]))
		{
			$collectionOptions = $collection->getOptions();
			$itemOptions = $collectionOptions['ITEM_OPTIONS'] ?? [];
			$this->collectionItems[$collection] = [
				$collectionOptions['ITEM_PROVIDER'], $itemOptions, $this->getValueHash('', $itemOptions),
			];
		}
		[$class, $options, $optionsHash] = $this->collectionItems[$collection];
		$previous = $this->expectedCollectionItem;
		$this->expectedCollectionItem = [$class, $id, $id . $optionsHash];
		try
		{
			return $this->getDataProvider($class, $id, $options, $collection->getParentProvider());
		}
		finally
		{
			$this->expectedCollectionItem = $previous;
		}
	}

	/** @internal */
	public function isCollectionItemRetained(?DataProvider $provider): bool
	{
		if ($provider === null || !isset($this->layerKeys[$provider]))
		{
			return false;
		}
		$key = $this->layerKeys[$provider];
		if (!isset($this->layerRecords[$key]))
		{
			return false;
		}
		[$class, $hash] = $this->layerRecords[$key];

		return ($this->providersCache[$class][$hash] ?? null) === $provider;
	}

	private function trackLayerProvider(
		DataProvider $provider, string $class, string $hash, ?DataProvider $parent, bool $isCollectionItem, bool $isNew
	): void
	{
		$key = serialize([$class, $hash]);
		$isLayer = isset($this->contextStack) && $this->contextStack->count() > 1
			&& ($isCollectionItem || ($parent && $this->isCollectionItemRetained($parent)));
		if (isset($this->layerTicks[$key]))
		{
			if ($isLayer)
			{
				$this->layerTicks[$key] = ++$this->layerTick;
			}
			else
			{
				$this->promoteLayerProvider($provider);
			}

			return;
		}
		// An evicted record recreated outside the layer keeps the decisions of its first instance.
		if (!$isLayer && $isNew && isset($this->layerAccessByKey[$key]))
		{
			$this->accessCache[spl_object_hash($provider)] = $this->layerAccessByKey[$key];
			unset($this->layerAccessByKey[$key]);
		}
		// Existing legacy records must retain their original lifetime and access cache.
		if (!$isLayer || !$isNew)
		{
			return;
		}
		$this->layerKeys ??= new \WeakMap();
		$this->layerChildren ??= new \WeakMap();
		$this->layerAccess ??= new \WeakMap();
		$this->layerKeys[$provider] = $key;
		$this->layerTicks[$key] = ++$this->layerTick;
		$this->layerRecords[$key] = [$class, $hash];
		if ($parent && $this->isCollectionItemRetained($parent))
		{
			if (!isset($this->layerChildren[$parent]))
			{
				$this->layerChildren[$parent] = new \ArrayObject();
			}
			$this->layerChildren[$parent][$key] = true;
		}
		$this->checkLayerPressure($provider);
	}

	private function promoteLayerProvider(DataProvider $provider): void
	{
		$key = $this->layerKeys[$provider];
		$decisions = ($this->layerAccessByKey[$key] ?? []) + ($this->layerAccess[$provider] ?? []);
		if ($decisions)
		{
			$this->accessCache[spl_object_hash($provider)] = $decisions;
		}
		foreach ($this->getLayerChildKeys($provider) as $childKey)
		{
			if (isset($this->layerRecords[$childKey]))
			{
				[$class, $hash] = $this->layerRecords[$childKey];
				$this->promoteLayerProvider($this->providersCache[$class][$hash]);
			}
		}
		$this->detachLayerChild($provider, $key);
		unset($this->layerTicks[$key], $this->layerRecords[$key], $this->layerAccessByKey[$key]);
		unset($this->layerKeys[$provider], $this->layerChildren[$provider], $this->layerAccess[$provider]);
	}

	/** @internal */
	public function onCollectionStep(): void
	{
		if ($this->pressurePolicy !== null)
		{
			$this->checkLayerPressure();
		}
	}

	private function checkLayerPressure(?DataProvider $newProvider = null): void
	{
		if ($this->pressurePolicy === null)
		{
			$locator = ServiceLocator::getInstance();
			$service = 'documentgenerator.dataProviderManager.pressurePolicy';
			$policy = $locator->has($service) ? $locator->get($service) : null;
			$this->pressurePolicy = $policy instanceof PressurePolicy ? $policy : new MemoryLimitPressurePolicy();
		}
		if (!$this->pressurePolicy->isUnderPressure() || $this->layerTicks === [])
		{
			return;
		}
		$pinned = [];
		foreach ($this->collectionItems ?? [] as $collection => $metadata)
		{
			foreach ($collection->getPinnedItems() as $item)
			{
				$pinned[spl_object_hash($item)] = true;
			}
		}
		if ($newProvider)
		{
			$pinned[spl_object_hash($newProvider)] = true;
		}
		$target = (int)ceil(count($this->layerTicks) * $this->pressurePolicy->getBatchShare());
		$ticks = $this->layerTicks;
		asort($ticks, SORT_NUMERIC);
		$evicted = 0;
		foreach ($ticks as $key => $tick)
		{
			if ($evicted >= $target)
			{
				break;
			}
			$evicted += $this->evictLayerRecord($key, $pinned);
		}
		$this->pressurePolicy->afterBatch($evicted);
	}

	private function evictLayerRecord(string $key, array $pinned): int
	{
		if (!isset($this->layerRecords[$key]))
		{
			return 0;
		}
		[$class, $hash] = $this->layerRecords[$key];
		$provider = $this->providersCache[$class][$hash];
		if ($this->hasPinnedDescendant($provider, $pinned))
		{
			return 0;
		}
		$evicted = 1;
		foreach ($this->getLayerChildKeys($provider) as $childKey)
		{
			$evicted += $this->evictLayerRecord($childKey, $pinned);
		}
		$this->detachLayerChild($provider, $key);
		unset($this->providersCache[$class][$hash], $this->layerTicks[$key], $this->layerRecords[$key]);
		unset($this->layerChildren[$provider]);

		return $evicted;
	}

	private function getLayerChildKeys(DataProvider $provider): array
	{
		return isset($this->layerChildren[$provider])
			? array_keys($this->layerChildren[$provider]->getArrayCopy()) : [];
	}

	private function detachLayerChild(DataProvider $provider, string $key): void
	{
		$parent = $provider->getParentProvider();
		if ($parent && isset($this->layerChildren[$parent]))
		{
			unset($this->layerChildren[$parent][$key]);
		}
	}

	private function hasPinnedDescendant(DataProvider $provider, array $pinned): bool
	{
		if (isset($pinned[spl_object_hash($provider)]))
		{
			return true;
		}
		foreach ($this->getLayerChildKeys($provider) as $key)
		{
			if (isset($this->layerRecords[$key]))
			{
				[$class, $hash] = $this->layerRecords[$key];
				if ($this->hasPinnedDescendant($this->providersCache[$class][$hash], $pinned))
				{
					return true;
				}
			}
		}

		return false;
	}

	private function isIdCollectionsEnabled(): bool
	{
		if($this->isIdCollectionsEnabled === null)
		{
			$this->isIdCollectionsEnabled = Feature::isEnabled(IdCollections::class);
		}

		return $this->isIdCollectionsEnabled;
	}

	/**
	 * Forms multi-level array [placeholder] => [value].
	 * For debug-use only.
	 *
	 * @param DataProvider $dataProvider
	 * @param array $params
	 * @param array $stack
	 * @return array
	 * @internal
	 */
	public function getArray(DataProvider $dataProvider, array $params = [], array $stack = []): array
	{
		$result = [];
		if(in_array(get_class($dataProvider), $stack, true))
		{
			return $result;
		}
		$stack[] = get_class($dataProvider);

		foreach($dataProvider->getFields() as $placeholder => $field)
		{
			$value = $dataProvider->getValue($placeholder);
			if(isset($params['rawValue']) && $params['rawValue'] === true && $value instanceof Value)
			{
				$value = $value->getValue();
			}
			elseif($value instanceof ArrayDataProvider && $value->getItemKey())
			{
				$values = $this->getArray($value, $params, $stack);
				foreach($value as $item)
				{
					$values[$value->getItemKey()][] = $this->getArray($item, $params, $stack);
				}
				$value = $values;
			}
			elseif($value instanceof DataProvider)
			{
				$value = $this->getArray($value, $params, $stack);
			}
			elseif(is_array($value))
			{
				if(isset($params['listAsArray']) && $params['listAsArray'] === true)
				{

				}
				elseif(isset($field['PROVIDER']))
				{
					$value = $this->getValueFromList($value);
					$value = $this->createDataProvider($field, $value, $dataProvider, $placeholder);
					if($value instanceof DataProvider)
					{
						$value = $this->getArray($value, $params, $stack);
					}
					else
					{
						$value = null;
					}
				}
			}
			$result[$placeholder] = $value;
		}

		return $result;
	}

	/**
	 * Get list of available DataProviders, filtered by $params
	 *
	 * @param array $params
	 * @return array
	 */
	public function getList(array $params = []): array
	{
		$providers = Registry\DataProvider::getList($params);
		$moduleId = null;
		if(
			isset($params['filter']['MODULE'])
			&& is_string($params['filter']['MODULE'])
			&& !empty($params['filter']['MODULE'])
		)
		{
			$moduleId = $params['filter']['MODULE'];
		}
		if($moduleId)
		{
			if(!ModuleManager::isModuleInstalled($moduleId) || !Loader::includeModule($moduleId))
			{
				$moduleId = null;
			}
		}
		if($moduleId)
		{
			foreach($providers as $key => $provider)
			{
				if(isset($provider['MODULE']) && $moduleId !== $provider['MODULE'])
				{
					unset($providers[$key]);
				}
			}
		}
		if($moduleId === Driver::REST_MODULE_ID)
		{
			$providers[mb_strtolower(Rest::class)] = [
				'CLASS' => Rest::class,
				'NAME' => Driver::REST_MODULE_ID,
				'MODULE' => Driver::REST_MODULE_ID,
			];
		}

		return $providers;
	}

	/**
	 * @param $providerClassName
	 * @param array $placeholders
	 * @param array $mainProviderOptions
	 * @param bool $isAddRootGroups
	 * @param bool $isCopyFields
	 * @return array
	 */
	public function getDefaultTemplateFields(
		$providerClassName,
		array $placeholders = [],
		array $mainProviderOptions = [],
		$isAddRootGroups = true,
		$isCopyFields = false
	): array
	{
		$fields = [];

		$sourceFields = $this->getProviderPlaceholders($providerClassName, $placeholders, $mainProviderOptions, $isCopyFields);
		$documentFields = $this->getProviderPlaceholders(DataProvider\Document::class);
		unset($documentFields['Source']);
		if($isAddRootGroups)
		{
			Loc::loadLanguageFile(__DIR__.'/document.php');
			foreach($documentFields as &$field)
			{
				array_unshift($field['GROUP'], Loc::getMessage('DOCUMENT_GROUP_NAME'));
			}
			foreach($sourceFields as &$field)
			{
				array_unshift($field['GROUP'], Loc::getMessage('DOCUMENT_GROUP_NAME'));
			}
			unset($field);
		}
		if(empty($placeholders))
		{
			$placeholders = array_merge(array_keys($sourceFields), array_keys($documentFields));
		}
		foreach($placeholders as $placeholder)
		{
			if(isset($sourceFields[$placeholder]))
			{
				$fields[$placeholder] = $sourceFields[$placeholder];
				$fields[$placeholder]['VALUE'] = Document::THIS_PLACEHOLDER.'.'.Template::MAIN_PROVIDER_PLACEHOLDER.'.'.$fields[$placeholder]['VALUE'];
			}
			elseif(isset($documentFields[$placeholder]))
			{
				$fields[$placeholder] = $documentFields[$placeholder];
				$fields[$placeholder]['VALUE'] = Document::THIS_PLACEHOLDER.'.'.Template::DOCUMENT_PROVIDER_PLACEHOLDER.'.'.$fields[$placeholder]['VALUE'];
			}
		}

		return $fields;
	}

	public function resolveProviderFields(DataProvider $root, array $paths, bool $isCopyFields = false): array
	{
		return ProviderFieldCatalog::resolveFields($this, $root, $paths, $isCopyFields, false);
	}

	public function resolveProviderPlaceholders(DataProvider $root, array $codes, bool $isCopyFields = false): array
	{
		return ProviderFieldCatalog::resolveFields($this, $root, $codes, $isCopyFields, true);
	}

	public function getProviderNodeChildren(
		DataProvider $root,
		string $path = '',
		?string $cursor = null,
		int $limit = 100,
		array $query = [],
		bool $isCopyFields = false
	): array
	{
		return ProviderFieldCatalog::getNodeChildren(
			$this, $this->getProviderFieldCursor(), $root, $path, $cursor, $limit, $query, $isCopyFields,
		);
	}

	protected function getProviderFieldCursor(): ProviderFieldCursor
	{
		return new ProviderFieldCursor();
	}

	/**
	 * Iterates provider fields in the same order and shape as the full legacy traversal.
	 *
	 * @return \Generator<int, array>
	 */
	public function iterateProviderFields(DataProvider $root, bool $isCopyFields = false): \Generator
	{
		yield from ProviderFieldIterator::iterate($this, $root, $isCopyFields);
	}

	/**
	 * Iterates the first raw occurrence of each default template field code.
	 *
	 * @return \Generator<string, array, mixed, array{exhausted: bool, matchedCount: ?int, scannedMatches: int}>
	 */
	public function iterateDefaultTemplateFields(
		string $providerClass,
		array $options = [],
		bool $addRootGroups = true,
		bool $isCopyFields = false,
		?callable $predicate = null,
		int $offset = 0,
		?int $limit = null
	): \Generator
	{
		return yield from ProviderFieldCatalog::iterateDefaultTemplateFields(
			$this,
			$providerClass,
			$options,
			$addRootGroups,
			$isCopyFields,
			$predicate,
			$offset,
			$limit,
		);
	}

	/**
	 * Returns all possible placeholders for DataProvider.
	 *
	 * @param string $providerClassName
	 * @param array $placeholders
	 * @param array $options
	 * @param bool $isCopyFields
	 * @return array
	 */
	public function getProviderPlaceholders(
		$providerClassName,
		array $placeholders = [],
		array $options = [],
		$isCopyFields = false
	): array
	{
		$result = [];
		$dataProvider = $this->getDataProvider($providerClassName, ' ', $options);
		if(!$dataProvider)
		{
			return $result;
		}

		if(empty($placeholders))
		{
			$placeholders = true;
		}
		$fields = $this->getProviderFields($dataProvider, $placeholders, $isCopyFields);
		foreach($fields as $field)
		{
			$result[$this->valueToPlaceholder($field['VALUE'])] = $field;
		}

		return $result;
	}

	/**
	 * Form a valid placeholder for $value.
	 * For example DATA_PROVIDER.FIELD => DataProviderField
	 *
	 * @param string $value
	 * @return string
	 */
	public function valueToPlaceholder(string $value): string
	{
		$placeholder = mb_strtolower($value);
		$placeholder = str_replace(['_', '.'], ' ', $placeholder);
		$placeholder = ucwords($placeholder);
		$placeholder = str_replace(' ', '', $placeholder);

		return $placeholder;
	}

	/**
	 * @param DataProvider $dataProvider
	 * @param string $placeholder
	 * @return bool|array
	 */
	public function getProviderField(DataProvider $dataProvider, $placeholder)
	{
		$nameParts = explode('.', $placeholder);
		if(count($nameParts) === 1)
		{
			return $dataProvider->getFields()[$placeholder];
		}

		$placeholder = array_shift($nameParts);
		$fieldDescription = $dataProvider->getFields()[$placeholder];
		if($fieldDescription)
		{
			$childDataProvider = $this->createDataProvider($fieldDescription, ' ', $dataProvider);
			if($childDataProvider)
			{
				return $this->getProviderField($childDataProvider, implode('.', $nameParts));
			}
		}

		return false;
	}

	/**
	 * Returns single-level array with all fields of a $parentDataProvider.
	 * Key - path from field names like PROVIDER.PROVIDER.FIELD
	 * Value - field description (VALUE, TITLE, TYPE)
	 *
	 * @param DataProvider $parentDataProvider
	 * @param array|bool $placeholders
	 * @param bool $isCopyFields
	 * @param array $chain
	 * @param array $group
	 * @param bool $isArray
	 * @param array $providers
	 * @param bool $stopRecursion
	 * @return array
	 */
	public function getProviderFields(
		DataProvider $parentDataProvider,
		$placeholders = [],
		$isCopyFields = false,
		array $chain = [],
		array $group = [],
		$isArray = false,
		array $providers = [],
		$stopRecursion = false
	): array
	{
		$values = [];
		if($parentDataProvider->isRootProvider())
		{
			$providers[] = get_class($parentDataProvider);
		}
		$fields = $parentDataProvider->getFields();
		if(is_array($placeholders) && empty($placeholders))
		{
			return $values;
		}
		$copyPlaceholders = [];
		if($isCopyFields)
		{
			// build copied placeholders map
			foreach($fields as $placeholder => $field)
			{
				if(isset($field['OPTIONS']['COPY']))
				{
					if(is_array($placeholders) && !empty($placeholders))
					{
						$copyChain = $chain;
						$copyChain[] = $placeholder;
						$currentValue = $this->valueToPlaceholder(implode('.', $copyChain));
						foreach($placeholders as $name)
						{
							if(mb_strpos($name, $currentValue) === 0)
							{
								$copyPlaceholders[$placeholder] = $field['OPTIONS']['COPY'];
								$placeholders[] = str_replace($this->valueToPlaceholder($placeholder), $this->valueToPlaceholder($field['OPTIONS']['COPY']), $name);
								break;
							}
						}
					}
					else
					{
						$copyPlaceholders[$placeholder] = $field['OPTIONS']['COPY'];
					}
				}
			}
		}
		foreach($fields as $placeholder => $field)
		{
			$chain[] = $placeholder;
			$goDeeper = true;
			if(is_array($placeholders))
			{
				$goDeeper = false;
				$currentValue = $this->valueToPlaceholder(implode('.', $chain));
				foreach($placeholders as $name)
				{
					if(mb_strpos($name, $currentValue) === 0)
					{
						$goDeeper = true;
						break;
					}
				}
			}
			if(!$goDeeper)
			{
				array_pop($chain);
				continue;
			}
			$dataProvider = $this->createDataProvider($field, ' ', $parentDataProvider);
			if(isset($field['TITLE']) && !empty($field['TITLE']))
			{
				$group[] = $field['TITLE'];
			}
			else
			{
				$group[] = $this->valueToPlaceholder($placeholder);
			}
			if(
				$dataProvider &&
				(($dataProvider->isRootProvider() && !$stopRecursion) ||
				(!$dataProvider->isRootProvider()))
			)
			{
				if($dataProvider instanceof ArrayDataProvider)
				{
					$isArray = true;
				}
				$stopRecursion = false;
				if(count($providers) > self::MAX_DEPTH_LEVEL_ROOT_PROVIDERS)
				{
					$stopRecursion = true;
				}
				$values = array_merge(
					$values,
					$this->getProviderFields(
						$dataProvider,
						$placeholders,
						$isCopyFields,
						$chain,
						$group,
						$isArray,
						$providers,
						$stopRecursion
					)
				);
				$isArray = false;
			}
			else
			{
				if($isArray)
				{
					$field['OPTIONS']['IS_ARRAY'] = true;
				}
				$value = implode('.', $chain);
				if($isCopyFields || (!$isCopyFields && !isset($field['OPTIONS']['COPY'])))
				{
					$values[] = array_merge($field, [
						'VALUE' => $value,
						'GROUP' => $group,
					]);
				}
			}
			array_pop($group);
			array_pop($chain);
		}
		foreach($copyPlaceholders as $destPlaceholder => $sourcePlaceholder)
		{
			foreach($values as $field)
			{
				if(is_string($field['VALUE']) && mb_strpos($field['VALUE'], $sourcePlaceholder) !== false)
				{
					$field['VALUE'] = str_replace($sourcePlaceholder, $destPlaceholder, $field['VALUE']);
					$values[] = $field;
				}
			}
		}

		return $values;
	}

	/**
	 * Returns valid string to use it as a key to store DataProvider instance in the cache
	 *
	 * @param mixed $value
	 * @param array $options
	 * @return string
	 */
	protected function getValueHash($value, array $options = []): string
	{
		$valueHash = $value;
		if(is_object($value))
		{
			$valueHash = spl_object_hash($value);
		}
		elseif(is_array($value))
		{
			$valueHash = $this->hashCanonicalForm($value);
		}

		// 'data' option contains pre-fetched entity fields and can be very large.
		// Provider identity is already determined by $value (entity ID), so we exclude it.
		unset($options['data']);
		$valueHash .= $this->hashCanonicalForm($options);

		return $valueHash;
	}

	private function hashCanonicalForm(array $value): string
	{
		$context = hash_init('md5');
		$providerHashes = [];
		$providersInPath = [];
		$this->feedCanonicalForm($context, $value, $providerHashes, $providersInPath);

		return hash_final($context);
	}

	private function feedCanonicalForm(\HashContext $context, mixed $value, array &$providerHashes, array &$providersInPath): bool
	{
		if (is_string($value))
		{
			hash_update($context, 's' . strlen($value) . ':');
			hash_update($context, $value);

			return false;
		}
		if (is_array($value))
		{
			$isList = array_is_list($value);
			hash_update($context, ($isList ? 'l' : 'a') . count($value) . '[');
			if (!$isList)
			{
				ksort($value, SORT_STRING);
			}
			$hasCycle = false;
			foreach ($value as $key => $item)
			{
				if (!$isList)
				{
					$this->feedCanonicalForm($context, $key, $providerHashes, $providersInPath);
				}
				$hasCycle = $this->feedCanonicalForm($context, $item, $providerHashes, $providersInPath) || $hasCycle;
			}
			hash_update($context, ']');

			return $hasCycle;
		}
		if ($value instanceof DataProvider)
		{
			$providerKey = spl_object_hash($value);
			if (isset($providersInPath[$providerKey]))
			{
				hash_update($context, 'c' . $providerKey . ';');

				return true;
			}
			if (!isset($providerHashes[$providerKey]))
			{
				$providersInPath[$providerKey] = true;
				$providerContext = hash_init('md5');
				$hasCycle = $this->feedCanonicalForm($providerContext, get_class($value), $providerHashes, $providersInPath);
				$hasCycle = $this->feedCanonicalForm($providerContext, $value->getSource(), $providerHashes, $providersInPath) || $hasCycle;
				$options = $value->getOptions();
				unset($options['data']);
				$hasCycle = $this->feedCanonicalForm($providerContext, $options, $providerHashes, $providersInPath) || $hasCycle;
				$parent = $value->getParentProvider();
				hash_update($providerContext, $parent instanceof DataProvider ? 'o' . spl_object_hash($parent) . ';' : 'n;');
				if ($value instanceof HashDataProvider && ($revision = $value->getExplicitDataRevision()) > 0)
				{
					hash_update($providerContext, 'u' . $providerKey . ':' . $revision . ';');
				}
				if ($value instanceof ArrayDataProvider && ($modifiedItems = $value->getModifiedItems()) !== null)
				{
					hash_update($providerContext, 'm');
					$hasCycle = $this->feedCanonicalForm($providerContext, $modifiedItems, $providerHashes, $providersInPath) || $hasCycle;
				}
				unset($providersInPath[$providerKey]);
				$providerHash = hash_final($providerContext);
				if (!$hasCycle)
				{
					$providerHashes[$providerKey] = $providerHash;
				}
			}
			else
			{
				$providerHash = $providerHashes[$providerKey];
				$hasCycle = false;
			}
			hash_update($context, 'p' . $providerHash . ';');

			return $hasCycle;
		}
		if (is_object($value))
		{
			hash_update($context, 'o' . spl_object_hash($value) . ';');

			return false;
		}
		hash_update($context, serialize($value));

		return false;
	}

	/**
	 * Removes $placeholder from $value names.
	 * Example: $values = [Placeholder.Provider => Value] => [Provider => Value] if $placeholder = 'Placeholder'
	 *
	 * @param array $values
	 * @param string $placeholder
	 * @return array
	 */
	protected function reformOptionValues(array $values, string $placeholder): array
	{
		$result = [];
		foreach($values as $name => $value)
		{
			$isForChildValue = false;
			$nameParts = explode('.', $name);
			if(count($nameParts) > 1 && $nameParts[0] == $placeholder)
			{
				array_shift($nameParts);
				$name = implode('.', $nameParts);
				$isForChildValue = true;
			}
			if($isForChildValue || (!$isForChildValue && !isset($result[$name])))
			{
				$result[$name] = $value;
			}
		}

		return $result;
	}

	/**
	 * Returns true if provider accepts array as main $value.
	 *
	 * @param $providerClassName
	 * @return bool
	 */
	public function isProviderArray($providerClassName): bool
	{
		return (
			is_a($providerClassName, ArrayDataProvider::class, true) ||
			is_a($providerClassName, HashDataProvider::class, true)
		);
	}

	/**
	 * @param array|mixed $values
	 * @param bool $firstAsDefault
	 * @return mixed
	 */
	public function getValueFromList($values, $firstAsDefault = false)
	{
		if(is_array($values))
		{
			foreach($values as $value)
			{
				if(is_array($value) && $value['SELECTED'])
				{
					return $value['VALUE'];
				}
			}
			if($firstAsDefault === true)
			{
				return reset($values)['VALUE'];
			}
		}

		return $values;
	}

	/**
	 * @param DataProvider $dataProvider
	 * @param string $code
	 * @return null|string
	 */
	public function getLangPhraseValue(DataProvider $dataProvider, $code): ?string
	{
		$phrasesPath = $dataProvider->getLangPhrasesPath();
		if($phrasesPath === null)
		{
			return '';
		}
		$region = $this->getRegion();
		$this->loadLangPhrases($phrasesPath, $region);

		if(isset($this->phrases[$region][$code]))
		{
			return $this->phrases[$region][$code];
		}

		return null;
	}

	/**
	 * @param $region
	 * @return $this
	 */
	public function setRegion($region): DataProviderManager
	{
		$this->getContext()->setRegion($region);

		return $this;
	}

	/**
	 * @return string
	 */
	public function getRegion(): string
	{
		return $this->getContext()->getRegion();
	}

	/**
	 * @return string
	 */
	public function getRegionLanguageId(): string
	{
		return $this->getContext()->getRegionLanguageId();
	}

	public function getTimezone(): string
	{
		return $this->getContext()->getTimezone();
	}

	/**
	 * @param string $path
	 * @param string $region
	 */
	protected function loadLangPhrases(string $path, string $region): void
	{
		if(isset($this->loadedPhrasePath[$path][$region]))
		{
			return;
		}

		$this->loadedPhrasePath[$path][$region] = true;
		$phrases = [];
		if(is_numeric($region))
		{
			$phraseList = RegionPhraseTable::getList([
				'filter' => [
					'REGION_ID' => $region,
				]
			]);
			while($phrase = $phraseList->fetch())
			{
				$phrases[$phrase['CODE']] = $phrase['PHRASE'];
			}
		}
		else
		{
			$file = new File($path.'/phrase_'.$region.'.php');
			if(!$file->isExists())
			{
				return;
			}

			/** @noinspection PhpIncludeInspection */
			$phrases = include $file->getPath();
		}
		if(!isset($this->phrases[$region]))
		{
			$this->phrases[$region] = [];
		}
		if(is_array($phrases))
		{
			$this->phrases[$region] = array_merge($this->phrases[$region], $phrases);
		}
	}

	/**
	 * @return Culture
	 */
	public function getCulture(): Culture
	{
		return $this->getContext()->getCulture();
	}

	/**
	 * @param DataProvider $dataProvider
	 * @param null $userId
	 * @return bool
	 */
	public function checkDataProviderAccess(DataProvider $dataProvider, $userId = null): bool
	{
		if(!$userId)
		{
			$userId = Driver::getInstance()->getUserId();
		}

		if($userId === 0)
		{
			return true;
		}

		if (isset($this->layerKeys[$dataProvider]))
		{
			$key = $this->layerKeys[$dataProvider];
			$decisions = $this->layerAccess[$dataProvider] ?? [];
			if (!array_key_exists($userId, $decisions))
			{
				$decisions[$userId] = $this->layerAccessByKey[$key][$userId] ?? $dataProvider->hasAccess($userId);
				$this->layerAccessByKey[$key][$userId] = $decisions[$userId];
				$this->layerAccess[$dataProvider] = $decisions;
			}

			return $decisions[$userId];
		}

		$providerHash = spl_object_hash($dataProvider);
		if(!isset($this->accessCache[$providerHash][$userId]))
		{
			$this->accessCache[$providerHash][$userId] = $dataProvider->hasAccess($userId);
		}

		return $this->accessCache[$providerHash][$userId];
	}

	/**
	 * @param $region
	 * @return array
	 */
	public function getRegionPhrases($region): array
	{
		$providers = $this->getList();
		$loadedProviders = [];
		foreach($providers as $providerDescription)
		{
			$this->getDataProviderRegionPhrases($providerDescription['CLASS'], $region, $loadedProviders);
			$loadedProviders[mb_strtolower($providerDescription['CLASS'])] = true;
		}

		return $this->phrases[$region];
	}

	/**
	 * @param string $providerClassName
	 * @param $region
	 * @param array $loadedProviders
	 * @param array $field
	 */
	public function getDataProviderRegionPhrases(
		$providerClassName,
		$region,
		&$loadedProviders = [],
		array $field = []
	): void
	{
		$providerClassName = mb_strtolower($providerClassName);
		if(isset($loadedProviders[$providerClassName]))
		{
			return;
		}
		if(!empty($field))
		{
			$provider = $this->createDataProvider($field, ' ');
		}
		else
		{
			$provider = $this->getDataProvider($providerClassName, ' ');
		}
		if($provider)
		{
			if($provider instanceof ArrayDataProvider)
			{
				$field = $provider->getFields()[$provider->getItemKey()];
				$provider = $this->createDataProvider($field, ' ');
				if(!$provider)
				{
					return;
				}
			}
			$phrasesPath = $provider->getLangPhrasesPath();
			if($phrasesPath)
			{
				$this->loadLangPhrases($phrasesPath, $region);
			}
			$loadedProviders[$providerClassName] = true;
			foreach($provider->getFields() as $placeholder => $providerField)
			{
				if(!empty($providerField['PROVIDER']) && !isset($loadedProviders[mb_strtolower($providerField['PROVIDER'])]))
				{
					$this->getDataProviderRegionPhrases($providerField['PROVIDER'], $region, $loadedProviders, $providerField);
				}
			}
		}
	}

	protected function isMultiple($value): bool
	{
		if(!is_array($value))
		{
			return false;
		}

		if($value instanceof \Traversable)
		{
			return true;
		}

		return array_keys($value) === range(0, count($value) - 1);
	}
}
