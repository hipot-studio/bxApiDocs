<?php

namespace Bitrix\Sign\Access;

use Bitrix\Crm;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\BaseAccessController;
use Bitrix\Main\Access\Event\EventDictionary;
use Bitrix\Main\Access\User\AccessibleUser;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Sign\Access\Model\UserModelRepository;
use Bitrix\Sign\Access\Permission\PermissionDictionary;
use Bitrix\Sign\Access\Permission\SignPermissionDictionary;
use Bitrix\Sign\Access\Rule\Factory\SignRuleFactory;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Debug;
use Bitrix\Sign\Factory\Access\AccessibleItemFactory;
use Bitrix\Sign\Helper\IterationHelper;
use Bitrix\Sign\Item;
use Bitrix\Sign\Service\Container;

class AccessController extends BaseAccessController
{
	public const RULE_OR = 'OR';
	public const RULE_AND = 'AND';

	private SignRuleFactory $signRuleFactory;
	/** @var array<int, array<string, array{general: bool, toItems: array<int, bool>}>> */
	protected array $userAccessCache = [];
	private readonly AccessibleItemFactory $accessibleItemFactory;
	private readonly UserModelRepository $userModelRepository;

	public function __construct($userId, ?UserModelRepository $userModelRepository = null)
	{
		parent::__construct($userId);
		$container = Container::instance();

		$this->signRuleFactory = new SignRuleFactory();
		$this->accessibleItemFactory = $container->getAccessibleItemFactory();
		$this->userModelRepository = $userModelRepository ?? $container->getAccessUserModelRepository();
	}

	public function checkByItem(string $action, Contract\Item $item, array $params = null): bool
	{
		$accessibleItem = $this->accessibleItemFactory->createFromItem($item);

		return $this->check($action, $accessibleItem, $params);
	}

	/**
	 * Checking access rights by action
	 *
	 * @param string $action
	 * @param AccessibleItem|null $item
	 * @param null $params
	 *
	 * @return bool
	 * @throws ArgumentException
	 */
	public function check(string $action, AccessibleItem|null $item = null, $params = null): bool
	{
		if (!Loader::includeModule('crm'))
		{
			return false;
		}

		if (isset($this->userAccessCache[$this->user->getUserId()][$action]))
		{
			$accessCache = $this->userAccessCache[$this->user->getUserId()][$action];
			if ($item === null && isset($accessCache['general']))
			{
				return $accessCache['general'];
			}
			if ($item !== null && isset($accessCache['toItems'][$item->getId()]))
			{
				return $accessCache['toItems'][$item->getId()];
			}
		}

		$ruleObject = $this->signRuleFactory->createFromAction($action, $this);
		if (!$ruleObject)
		{
			throw new ArgumentException($action);
		}

		$event = $this->sendEvent(EventDictionary::EVENT_ON_BEFORE_CHECK, $action, null, $params);
		$isAccess = $event->isAccess();
		if ($isAccess !== null)
		{
			$this->updateCache($item, $action, $isAccess);

			return $isAccess;
		}

		$params['action'] = $action;
		$isAccess = $ruleObject->execute($item, $params);
		if ($isAccess)
		{
			$this->updateCache($item, $action, $isAccess);

			return true;
		}

		$event = $this->sendEvent(EventDictionary::EVENT_ON_AFTER_CHECK,  $action,null, $params, false);
		$isAccess = $event->isAccess() ?? false;
		$this->updateCache($item, $action, $isAccess);

		return $isAccess;
	}

	private function updateCache(?AccessibleItem $item, string $action, bool $hasAccess): void
	{
		$userId = $this->user->getUserId();
		if ($item === null)
		{
			$this->userAccessCache[$userId][$action]['general'] = $hasAccess;
		}
		else
		{
			$this->userAccessCache[$userId][$action]['toItems'][$item->getId()] = $hasAccess;
		}
	}

	protected function loadItem(int $itemId = null): ?AccessibleItem
	{
		return null;
	}

	protected function loadUser(int $userId): AccessibleUser
	{
		return Container::instance()->getAccessUserModelRepository()
			->getByUserId($userId)
		;
	}

	/**
	 * @param list<SignPermissionDictionary::*, PermissionDictionary::*> $actions
	 */
	public function checkAll(array $actions, AccessibleItem|null $item = null, $params = null): bool
	{
		return IterationHelper::all(
			$actions,
			fn(int|string $action): bool => $this->check($action, $item, $params),
		);
	}

	/**
	 * @param list<SignPermissionDictionary::*, PermissionDictionary::*> $actions
	 */
	public function checkAny(array $actions, AccessibleItem|null $item = null, $params = null): bool
	{
		foreach ($actions as $action)
		{
			if ($this->check($action, $item, $params))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Ids of the items accessible by at least one of the actions, the semantics of {@see self::checkAny()}
	 * applied to a whole set at once.
	 *
	 * The method decides nothing on its own: it prepares the CRM permission attributes of the set and then
	 * asks {@see self::check()} about every item, so the verdict stays where it was - in the rule. An error
	 * in the preparation costs speed, never access.
	 *
	 * The answer is keyed by item id, so an unsaved item - one whose id is still zero - has no addressable
	 * entry of its own and would share it with every other unsaved item of the set, letting an accessible
	 * one answer for a denied one. Such items are dropped fail-closed; callers pass items loaded from
	 * repositories, where the id is always there.
	 *
	 * @param list<SignPermissionDictionary::*, PermissionDictionary::*> $actions
	 * @param iterable<Contract\Item> $items saved items of one type; ids of different domains are not comparable
	 *
	 * @return array<int, true> ids of the accessible items
	 * @throws ArgumentException on an unknown action or on a set mixing item types
	 */
	public function filterAccessibleIds(array $actions, iterable $items): array
	{
		$accessibleItems = [];
		$itemClass = null;
		foreach ($items as $item)
		{
			$accessibleItem = $this->accessibleItemFactory->createFromItem($item);
			if ($accessibleItem === null)
			{
				// Fail-closed: checkByItem() degrades to the general permission here, and the batch must
				// not reproduce that degradation.
				continue;
			}

			// The result is keyed by item id, and documents, templates, folders and signers lists number
			// themselves independently, so a mixed set has no distinguishable answer. The guard keys on the
			// domain item: the factory folds several domains into one access item and would not tell them apart.
			$itemClass ??= get_class($item);
			if (get_class($item) !== $itemClass)
			{
				throw new ArgumentException('Items of a batch access check must be of the same type');
			}

			if ($accessibleItem->getId() <= 0)
			{
				// Fail-closed: an unsaved item has no key of its own, and two of them would share one - an
				// accessible item would then answer for a denied one.
				continue;
			}

			$accessibleItems[] = $accessibleItem;
		}

		if ($actions === [])
		{
			return [];
		}

		$this->preloadCrmPermissionAttributes($accessibleItems);

		$accessibleIds = [];
		foreach ($accessibleItems as $accessibleItem)
		{
			foreach ($actions as $action)
			{
				if ($this->check($action, $accessibleItem))
				{
					$accessibleIds[$accessibleItem->getId()] = true;

					break;
				}
			}
		}

		return $accessibleIds;
	}

	/**
	 * Reads the CRM permission attributes of the whole set at once, so that the per-item checks find them in
	 * memory. Optional for correctness: skipped entirely, the checks answer the same, only slower - which is
	 * why a failure of the preparation is logged and swallowed instead of reaching the caller.
	 *
	 * Not every item of the set profits from it: an item whose CRM entity does not exist is not cached by CRM
	 * at all, so its per-item check goes to the database anyway.
	 *
	 * @param list<Contract\Access\AccessibleItem> $accessibleItems
	 */
	private function preloadCrmPermissionAttributes(array $accessibleItems): void
	{
		try
		{
			$this->readCrmPermissionAttributes($accessibleItems);
		}
		catch (\Throwable $exception)
		{
			$this->getLogger()->error(
				'Unable to preload the CRM permission attributes: ' . $exception->getMessage(),
			);
		}
	}

	/**
	 * @param list<Contract\Access\AccessibleItem> $accessibleItems
	 */
	private function readCrmPermissionAttributes(array $accessibleItems): void
	{
		if ($this->user->isAdmin())
		{
			// An admin verdict never reads permission attributes, so preloading them would only add queries.
			return;
		}

		if (!Loader::includeModule('crm'))
		{
			return;
		}

		$crmIdsByEntityTypeId = [];
		foreach ($accessibleItems as $accessibleItem)
		{
			if (!$accessibleItem instanceof Contract\Item\ItemWithCrmEntity)
			{
				continue;
			}

			if ($accessibleItem instanceof Item\Access\Document && $accessibleItem->isTemplated())
			{
				// A templated document is answered by the sign permission of its template alone
				// (BaseRule::checkDocumentTemplateAccess()) and never reads the CRM attributes.
				continue;
			}

			$entityTypeId = $accessibleItem->getCrmEntityTypeId();
			$crmId = $accessibleItem->getCrmId();
			if ($entityTypeId === null || $crmId <= 0)
			{
				continue;
			}

			$crmIdsByEntityTypeId[$entityTypeId][] = $crmId;
		}

		if ($crmIdsByEntityTypeId === [])
		{
			return;
		}

		$itemPermissions = Crm\Service\Container::getInstance()
			->getUserPermissions($this->user->getUserId())
			->item()
		;
		foreach ($crmIdsByEntityTypeId as $entityTypeId => $crmIds)
		{
			$itemPermissions->preloadPermissionAttributes($entityTypeId, array_values(array_unique($crmIds)));
		}
	}

	private function getLogger(): Debug\Logger
	{
		return Container::instance()->getLogger('Access');
	}
}
