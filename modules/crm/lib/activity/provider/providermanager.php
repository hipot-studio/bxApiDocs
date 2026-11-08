<?php
namespace Bitrix\Crm\Activity\Provider;

use Bitrix\Crm\Badge\SourceIdentifier;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ProviderRegistry;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ProviderRegistryInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query;

Loc::loadMessages(__FILE__);

class ProviderManager
{
	private static array|null $providers = null;

	private static array|null $allProviders = null;
	private static array|null $allProvidersEntities = null;
	private static array|null $relatedFilterProviders = null;

	/**
	 * @return Base[] - List of providers.
	 */
	public static function getProviders(): array
	{
		if(self::$providers === null)
		{
			self::$providers = self::prepareProviderList();
		}

		return self::$providers;
	}

	public static function getAllProviders(): array
	{
		if(self::$allProviders === null)
		{
			self::$allProviders = self::prepareProviderList(false);
		}

		return self::$allProviders;
	}

	/**
	 * The providers of an activity by the value of the PROVIDER_ID column, as the registry composes them.
	 *
	 * WHICH PROVIDERS EXIST IS ANSWERED IN ONE PLACE NOW ({@see ProviderRegistry}), and this list is a reading
	 * of it rather than a literal of its own. Nothing about the answer changes: the providers, their order and
	 * the conditions of their membership are the ones this method has always had, the availability of a provider
	 * is still what $checkAvailable asks about, and the provider of repeat sales is still missing from both
	 * readings while its feature is off. Reading the internals of V2 from here is the agreed transitional
	 * exception to the layering of the module, as it is for the facade of the activities.
	 *
	 * @return array<string, string> the class of every provider by its identifier
	 */
	public static function prepareProviderList(bool $checkAvailable = true): array
	{
		$providersList = [];
		foreach (self::registry()->getAll(!$checkAvailable) as $providerId => $descriptor)
		{
			// A provider belonging to the registry for the sake of the timeline lists alone was never part of
			// this list, and adding it here would give the callers a provider legacy never gave them.
			if ($descriptor->isMembershipOnly())
			{
				continue;
			}

			$className = $descriptor->getClassName();

			// The readers of this list call the STATIC hooks of the legacy contract on the class name it hands
			// out ($className::isCompletable(), ::getName(), ::processCreation() and the rest), and a class
			// written against the contract of V2 answers those very names as INSTANCE methods. A provider that
			// brings no legacy class at all would therefore make every legacy reader call an instance method
			// statically, so it is left out here - which is what legacy answered about it anyway.
			if (!is_a($className, Base::class, true))
			{
				continue;
			}

			$providersList[$providerId] = $className;
		}

		return array_merge($providersList, self::anotherProviders());
	}

	private static function anotherProviders(): array
	{
		static $anotherProviders = null;

		if ($anotherProviders !== null)
		{
			return $anotherProviders;
		}

		$another = [];

		foreach(GetModuleEvents('crm', 'OnGetActivityProviders', true) as $event)
		{
			$result = (array)ExecuteModuleEventEx($event);
			foreach ($result as $provider)
			{
				/** @var \Bitrix\Crm\Activity\Provider\Base $provider */
				$provider = (string)$provider;
				if ($provider
					&& class_exists($provider)
					&& (is_subclass_of($provider, Base::className()) || in_array(Base::className(), class_implements($provider)))
				)
				{
					$another[$provider::getId()] = $provider;
				}
			}
		}

		$anotherProviders = $another;

		return $anotherProviders;
	}

	/**
	 * Get completable providers
	 * @return array
	 */
	public static function getCompletableProviderList()
	{
		$results = array();
		foreach(self::getProviders() as $providerID => $provider)
		{
			/** @var \Bitrix\Crm\Activity\Provider\Base $provider */
			if($provider::isCompletable())
			{
				$results[] = array('ID' => $providerID, 'NAME' => $provider::getName());
			}
		}
		return $results;
	}

	public static function getCompletableProviderIdFlatList(): array
	{
		return array_map(fn(array $provider) => $provider['ID'], self::getCompletableProviderList());
	}

	public static function transferOwnership($oldEntityTypeId, $oldEntityId, $newEntityTypeId, $newEntityId)
	{
		foreach(self::getProviders() as $provider)
		{
			/** @var \Bitrix\Crm\Activity\Provider\Base $provider */
			$provider::transferOwnership($oldEntityTypeId, $oldEntityId, $newEntityTypeId, $newEntityId);
		}
	}

	public static function deleteByOwner($entityTypeId, $entityId)
	{
		foreach(self::getProviders() as $provider)
		{
			/** @var \Bitrix\Crm\Activity\Provider\Base $provider */
			$provider::deleteByOwner($entityTypeId, $entityId);
		}
	}

	/**
	 * @return int
	 */
	public static function prepareToolbarButtons(array &$buttons, array $params = null)
	{
		if(!is_array($params))
		{
			$params = array();
		}

		$ownerTypeID = isset($params['OWNER_TYPE_ID']) ? (int)$params['OWNER_TYPE_ID'] : \CCrmOwnerType::Undefined;
		$ownerID = isset($params['OWNER_ID']) ? (int)$params['OWNER_ID'] : 0;
		$count = 0;
		$providerParams = array('OWNER_TYPE_ID' => $ownerTypeID, 'OWNER_ID' => $ownerID);
		foreach(self::getProviders() as $provider)
		{
			foreach($provider::getPlannerActions($providerParams) as $action)
			{
				$name = isset($action['NAME']) ? $action['NAME'] : '';
				if($name === '')
				{
					continue;
				}

				$action = array_merge($action, array('OWNER_TYPE_ID' => $ownerTypeID, 'OWNER_ID' => $ownerID));
				$actionParams = htmlspecialcharsbx(\CUtil::PhpToJSObject($action));
				$buttons[] = array(
					'TEXT' => $name,
					'TITLE' => $name,
					'ONCLICK' => "(new BX.Crm.Activity.Planner()).showEdit({$actionParams})",
					'ICON' => 'btn-new'
				);
				$count++;
			}

			$count += $provider::prepareToolbarButtons($buttons, $params);
		}

		return $count;
	}

	/**
	 * Process activity creation.
	 * @param array $activityFields
	 * @param array|null $params
	 */
	public static function processCreation(array $activityFields, array $params = null)
	{
		foreach(self::getProviders() as $provider)
		{
			$provider::processCreation($activityFields, $params);
		}
	}

	/**
	 * @param int $activityId
	 * @param array $activityFields - fields after an update, in other words - current fields
	 * @param array $bindings - bindings that were not changed in this update (there is a separate method for bindings change)
	 * @return void
	 */
	final public static function syncBadgesOnActivityUpdate(int $activityId, array $activityFields, array $bindings = []): void
	{
		$provider = \CCrmActivity::GetActivityProvider($activityFields);
		if (!isset($provider))
		{
			return;
		}

		if (empty($bindings))
		{
			$activityBindings = \CCrmActivity::GetBindings($activityId);
			$bindings = is_array($activityBindings) ? $activityBindings : [];
		}

		$bindingsFiltered = self::filterBindings($bindings);
		if (empty($bindingsFiltered))
		{
			return;
		}

		$provider::syncBadges($activityId, $activityFields, $bindingsFiltered);
	}

	final public static function syncBadgesOnBindingsChange(
		int $activityId,
		array $addedBindings,
		array $removedBindings
	): void
	{
		$container = Container::getInstance();
		$source = new SourceIdentifier(
			SourceIdentifier::CRM_OWNER_TYPE_PROVIDER,
			\CCrmOwnerType::Activity,
			$activityId,
		);

		$activity = $container->getActivityBroker()->getById($activityId);
		if ($activity)
		{
			$provider = \CCrmActivity::GetActivityProvider($activity);
			if ($provider)
			{
				$provider::syncBadges(
					$activityId,
					$activity,
					self::filterBindings($addedBindings),
				);
			}
		}

		$removedBindingsFiltered = self::filterBindings($removedBindings);
		if (empty($removedBindingsFiltered))
		{
			return;
		}

		$badgesToRemoveQuery =
			\Bitrix\Crm\Badge\Model\BadgeTable::query()
				->setSelect(['TYPE', 'VALUE', 'ENTITY_TYPE_ID', 'ENTITY_ID'])
				->where('SOURCE_PROVIDER_ID', $source->getProviderId())
				->where('SOURCE_ENTITY_TYPE_ID', $source->getEntityTypeId())
				->where('SOURCE_ENTITY_ID', $source->getEntityId())
		;
		$bindingsFilter = Query\Query::filter()->logic(Query\Filter\ConditionTree::LOGIC_OR);
		foreach ($removedBindingsFiltered as $binding)
		{
			$bindingsFilter->where(
				Query\Query::filter()
					->where('ENTITY_TYPE_ID', $binding['OWNER_TYPE_ID'])
					->where('ENTITY_ID', $binding['OWNER_ID'])
			);
		}

		$badgesToRemoveQuery->where($bindingsFilter);

		foreach ($badgesToRemoveQuery->fetchCollection() as $badgeToRemove)
		{
			$badge = $container->getBadge($badgeToRemove->get('TYPE'), $badgeToRemove->get('VALUE'));
			$badge->unbind(
				new ItemIdentifier($badgeToRemove->get('ENTITY_TYPE_ID'), $badgeToRemove->get('ENTITY_ID')),
				$source,
			);
		}
	}

	private static function filterBindings(array $bindings): array
	{
		return array_filter(
			$bindings,
			static function ($binding): bool {
				return (
					is_array($binding)
					&& isset($binding['OWNER_TYPE_ID'])
					&& isset($binding['OWNER_ID'])
					&& \CCrmOwnerType::IsDefined($binding['OWNER_TYPE_ID'])
					&& (int)$binding['OWNER_ID'] > 0
				);
			}
		);
	}

	public static function getProviderEntity(string $providerId): ?string
	{
		if (self::$allProvidersEntities === null)
		{
			self::$allProvidersEntities = self::prepareProviderEntityList();
		}

		return self::$allProvidersEntities[$providerId] ?? null;
	}

	/**
	 * The class of the domain entity behind the activities of a provider, for the providers that have one.
	 *
	 * @return array<string, string>
	 */
	private static function prepareProviderEntityList(): array
	{
		$entities = [];
		foreach (self::registry()->getAll(true) as $providerId => $descriptor)
		{
			$entityClassName = $descriptor->getEntityClassName();
			if ($entityClassName !== null)
			{
				$entities[$providerId] = $entityClassName;
			}
		}

		return $entities;
	}

	/**
	 * The providers taking part in the filter over the activities related to a CRM object.
	 *
	 * @return string[]
	 */
	private static function prepareRelatedFilterProviders(): array
	{
		$providerIds = [];
		foreach (self::registry()->getAll(true) as $providerId => $descriptor)
		{
			if ($descriptor->isRelatedFilterProvider())
			{
				$providerIds[] = $providerId;
			}
		}

		return $providerIds;
	}

	public static function getRelatedFilterProviders(): array
	{
		if (self::$relatedFilterProviders === null)
		{
			self::$relatedFilterProviders = self::prepareRelatedFilterProviders();
		}

		return self::$relatedFilterProviders;
	}

	public static function isRelatedFilterProvider(string $providerId): bool
	{
		return in_array($providerId, self::getRelatedFilterProviders());
	}

	/**
	 * The one registry of the hit. Whoever registers a provider from another module registers it there, so
	 * building a second one here would hide it from these lists.
	 */
	private static function registry(): ProviderRegistryInterface
	{
		return ServiceLocator::getInstance()->get(ProviderRegistry::SERVICE_CODE);
	}
}
