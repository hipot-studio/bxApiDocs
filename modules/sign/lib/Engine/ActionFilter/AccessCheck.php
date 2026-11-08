<?php

namespace Bitrix\Sign\Engine\ActionFilter;

use Bitrix\Main;
use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Attribute\Access\LogicAnd;
use Bitrix\Sign\Attribute\Access\LogicOr;
use Bitrix\Sign\Attribute\ActionAccess;
use Bitrix\Sign\Item\Document\SafeFolderCollection;
use Bitrix\Sign\Item\Document\TemplateCollection;
use Bitrix\Sign\Item\Document\TemplateFolderCollection;
use Bitrix\Sign\Item\DocumentCollection;
use Bitrix\Sign\Item\SignersListCollection;
use Bitrix\Sign\Repository\Document\SafeFolderRepository;
use Bitrix\Sign\Repository\Document\TemplateFolderRepository;
use Bitrix\Sign\Repository\Document\TemplateRepository;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\SignersListService;
use Bitrix\Sign\Type\Access\AccessibleItemType;
use Bitrix\Sign\Type\Access\ItemIdentifierType;

final class AccessCheck extends Main\Engine\ActionFilter\Base
{
	public const PREFILTER_KEY = 'ACCESS_CHECK';
	private const ERROR_INVALID_AUTHENTICATION = 'invalid_authentication';

	private ?AccessController $accessController = null;
	private readonly DocumentRepository $documentRepository;
	/** @var array<string, RuleWithPayload>  */
	private array $rules = [];
	/** @var list<LogicRule>  */
	private array $logicRules = [];
	/** @var array<string, list<Main\Access\AccessibleItem>> */
	private array $accessibleItemsCache = [];
	private readonly TemplateRepository $templateRepository;
	private readonly TemplateFolderRepository $templateFolderRepository;
	private readonly SafeFolderRepository $safeFolderRepository;
	private readonly SignersListService $signersListService;
	private readonly MemberRepository $memberRepository;

	public function __construct()
	{
		parent::__construct();

		$this->documentRepository = Container::instance()->getDocumentRepository();
		$this->memberRepository = Container::instance()->getMemberRepository();
		$this->templateRepository = Container::instance()->getDocumentTemplateRepository();
		$this->templateFolderRepository = Container::instance()->getTemplateFolderRepository();
		$this->safeFolderRepository = Container::instance()->getSafeFolderRepository();
		$this->signersListService = Container::instance()->getSignersListService();
	}

	private function getAccessController(): AccessController
	{
		return $this->accessController ??= new AccessController(
			(int)(Main\Engine\CurrentUser::get()->getId()),
		);
	}

	public function addRuleFromAttribute(ActionAccess|LogicOr|LogicAnd $attribute): self
	{
		if ($attribute instanceof ActionAccess)
		{
			return $this->addRule(
				$attribute->permission,
				$attribute->itemType,
				$attribute->itemIdOrUidRequestKey,
				$attribute->identifiedBy,
			);
		}

		return $this->addLogicRuleFromAttribute($attribute);
	}

	private function addRule(
		string $accessPermission,
		?string $itemType = null,
		?string $itemIdOrUidRequestKey = null,
		?string $identifiedBy = null,
	): self
	{
		$this->rules[$accessPermission] = $this->createRuleWithPayload(
			$accessPermission,
			$itemType,
			$itemIdOrUidRequestKey,
			$identifiedBy,
		);

		return $this;
	}

	private function addLogicRuleFromAttribute(LogicAnd|LogicOr $logicAttribute): self
	{
		$rules = array_map(
			fn(ActionAccess $condition) => $this->createRuleWithPayload(
				$condition->permission,
				$condition->itemType,
				$condition->itemIdOrUidRequestKey,
				$condition->identifiedBy,
			),
			$logicAttribute->conditions,
		);

		$this->logicRules[] = new LogicRule(
			$logicAttribute instanceof LogicOr ? AccessController::RULE_OR : AccessController::RULE_AND,
			...$rules,
		);

		return $this;
	}

	public function onBeforeAction(Main\Event $event): ?Main\EventResult
	{
		foreach ($this->rules as $rule)
		{
			if ($this->hasInvalidItemIdentifier($rule))
			{
				return $this->getAuthErrorResult();
			}
			if (!$this->checkRuleWithPayload($rule))
			{
				return $this->getAuthErrorResult();
			}
		}

		foreach ($this->logicRules as $logicRule)
		{
			if (!$this->checkLogicRule($logicRule))
			{
				return $this->getAuthErrorResult();
			}
		}

		return null;
	}

	private function getAuthErrorResult(): Main\EventResult
	{
		Main\Context::getCurrent()->getResponse()->setStatus(401);
		$this->addError(new Main\Error(
			Main\Localization\Loc::getMessage('SIGN_ENGINE_ACTION_FILTER_ACCESS_CHECK_ERROR_ACCESS_DENIED'),
			self::ERROR_INVALID_AUTHENTICATION),
		);

		return new Main\EventResult(Main\EventResult::ERROR, null, null, $this);
	}

	private function checkLogicRule(LogicRule $rule): bool
	{
		if ($rule->logicOperator === AccessController::RULE_OR)
		{
			foreach ($rule->rules as $ruleWithPayload)
			{
				if ($this->checkRuleWithPayload($ruleWithPayload))
				{
					return true;
				}
			}

			return false;
		}

		foreach ($rule->rules as $ruleWithPayload)
		{
			if (!$this->checkRuleWithPayload($ruleWithPayload))
			{
				return false;
			}
		}

		return true;
	}

	private function checkRuleWithPayload(RuleWithPayload $rule): bool
	{
		if (!isset($rule->passes))
		{
			if ($this->hasInvalidItemIdentifier($rule))
			{
				// Reached e.g. on a non-JSON transport, where the identifier is absent from the request.
				$rule->passes = false;
			}
			else
			{
				$accessibleItems = $this->createAccessibleItems($rule);
				if ($accessibleItems === [] && $rule->identifiedBy !== null)
				{
					// The rule resolves its item through another entity, so an empty result means
					// the request named an entity that does not exist. Falling back to a global
					// permission check would let an owner-scoped grant through — fail-closed.
					$rule->passes = false;
				}
				else
				{
					$rule->passes = $this->checkPermission($rule->accessPermission, $accessibleItems);
				}
			}
		}

		return $rule->passes;
	}


	/**
	 * @param RuleWithPayload $rule
	 *
	 * @return list<Main\Access\AccessibleItem>
	 */
	private function createAccessibleItems(RuleWithPayload $rule): array
	{
		if (empty($rule->itemType) || empty($rule->itemIdOrUidRequestKey))
		{
			return [];
		}

		// Branches of a logic rule usually share the same object: request sources do not change
		// while the action is being processed, so the object is resolved once per type and key.
		$cacheKey = $rule->itemType . '|' . ($rule->identifiedBy ?? '') . '|' . $rule->itemIdOrUidRequestKey;
		if (isset($this->accessibleItemsCache[$cacheKey]))
		{
			return $this->accessibleItemsCache[$cacheKey];
		}

		$idOrUid = $this->resolveItemIdentifier($rule->itemIdOrUidRequestKey);
		if ($idOrUid === null)
		{
			return [];
		}

		$idsOrUids = $this->convertIdOrUidToArray($idOrUid);
		$items = $rule->identifiedBy === null
			? $this->createItemsByOwnIdentifier($rule->itemType, $idsOrUids)
			: $this->createItemsByRelatedIdentifier($rule->identifiedBy, $idsOrUids)
		;

		$accessItems = [];
		if ($items instanceof \Traversable)
		{
			foreach ($items as $item)
			{
				$accessItem = Container::instance()->getAccessibleItemFactory()->createFromItem($item);
				if ($accessItem instanceof Main\Access\AccessibleItem)
				{
					$accessItems[] = $accessItem;
				}
			}
		}

		$this->accessibleItemsCache[$cacheKey] = $accessItems;

		return $accessItems;
	}

	private function createItemsByOwnIdentifier(string $itemType, array $idsOrUids): ?\Traversable
	{
		return match ($itemType)
		{
			AccessibleItemType::DOCUMENT => $this->getDocumentByIds($idsOrUids),
			AccessibleItemType::TEMPLATE => $this->getTemplatesByIds($idsOrUids),
			AccessibleItemType::TEMPLATE_FOLDER => $this->getTemplateFoldersByIds($idsOrUids),
			AccessibleItemType::SAFE_FOLDER => $this->getSafeFoldersByIds($idsOrUids),
			AccessibleItemType::SIGNERS_LIST => $this->getSignersListsByIds($idsOrUids),
			default => null,
		};
	}

	/**
	 * Resolves the access item from an identifier of a related entity. A member has no permission
	 * of its own: the action on it is authorized by the permission on the document the member
	 * belongs to. The kind of identifier comes from the attribute, so a uid made of digits is
	 * never read as a primary key.
	 */
	private function createItemsByRelatedIdentifier(string $identifiedBy, array $idsOrUids): ?DocumentCollection
	{
		return match ($identifiedBy)
		{
			ItemIdentifierType::MEMBER_UID => $this->getDocumentsByMemberUids($idsOrUids),
			default => null,
		};
	}

	private function createRuleWithPayload(
		string $accessPermission,
		?string $itemType,
		?string $itemIdOrUidRequestKey,
		?string $identifiedBy = null,
	): RuleWithPayload
	{
		return new RuleWithPayload($accessPermission, $itemType, $itemIdOrUidRequestKey, $identifiedBy);
	}

	private function hasInvalidItemIdentifier(RuleWithPayload $rule): bool
	{
		if ($rule->itemType === null)
		{
			return false;
		}

		if ($rule->itemIdOrUidRequestKey === null)
		{
			return true;
		}

		// An unknown object type resolves to nothing, and an empty resolution means a global
		// permission check: a typo in the attribute would silently turn an item-aware rule into a
		// category-level one. Same reason as below — a configuration error must not open a rule.
		if (!in_array($rule->itemType, AccessibleItemType::getAll(), true))
		{
			return true;
		}

		// A rule that resolves the item through another entity must name a supported pair:
		// an unknown one is a configuration error and must not reach a global permission check.
		if ($rule->identifiedBy !== null && !$this->isRelatedIdentifierSupported($rule))
		{
			return true;
		}

		// Every action addressed by a related identifier takes a scalar one, and the filter runs
		// before the binder rejects anything else. An array would otherwise be turned into a single
		// unbounded WHERE IN of whatever size the caller sent.
		if ($rule->identifiedBy !== null && is_array($this->resolveItemIdentifier($rule->itemIdOrUidRequestKey)))
		{
			return true;
		}

		// The identifier must come from exactly one request source: fail-closed, so neither a missing
		// key nor presence in several sources degrades an item-aware rule into a global check.
		return $this->countParameterSources($rule->itemIdOrUidRequestKey) !== 1;
	}

	private function isRelatedIdentifierSupported(RuleWithPayload $rule): bool
	{
		// A member identifier resolves to the document the member belongs to.
		return $rule->itemType === AccessibleItemType::DOCUMENT
			&& in_array($rule->identifiedBy, ItemIdentifierType::getAll(), true)
		;
	}

	/**
	 * Counts the request sources that contain the key, over the action binder's source list.
	 *
	 * @see Bitrix\Main\Engine\AutoWire\Binder::findParameterInSourceList
	 */
	private function countParameterSources(string $key): int
	{
		$count = 0;
		foreach ($this->getAction()->getController()->getSourceParametersList() as $source)
		{
			if ($this->isKeyPresentInSource($source, $key))
			{
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Returns the key value from the first source that contains it, or null.
	 *
	 * @see Bitrix\Main\Engine\AutoWire\Binder::findParameterInSourceList
	 */
	private function resolveItemIdentifier(string $key): mixed
	{
		foreach ($this->getAction()->getController()->getSourceParametersList() as $source)
		{
			if ($this->isKeyPresentInSource($source, $key))
			{
				return $source[$key];
			}
		}

		return null;
	}

	private function isKeyPresentInSource(mixed $source, string $key): bool
	{
		if (isset($source[$key]))
		{
			return true;
		}

		if ($source instanceof \ArrayAccess)
		{
			return $source->offsetExists($key);
		}

		if (is_array($source))
		{
			return array_key_exists($key, $source);
		}

		return false;
	}


	private function convertIdOrUidToArray(mixed $idOrUid): array
	{
		if (is_array($idOrUid))
		{
			return $idOrUid;
		}

		if (is_scalar($idOrUid) && !empty($idOrUid))
		{
			return [$idOrUid];
		}

		return [];
	}

	private function getDocumentByIds(array $idsOrUids): DocumentCollection
	{
		$firstIdOrUid = $idsOrUids[array_key_first($idsOrUids)] ?? null;
		if (empty($firstIdOrUid))
		{
			return new DocumentCollection();
		}

		if (is_numeric($firstIdOrUid))
		{
			$ids = array_map(static fn(mixed $value) => (int)$value, $idsOrUids);

			return $this->documentRepository->listByIds($ids);
		}
		elseif (is_string($firstIdOrUid))
		{
			$uids = array_map(static fn(mixed $value) => (string)$value, $idsOrUids);

			return $this->documentRepository->listByUids($uids);
		}

		return new DocumentCollection();
	}

	/**
	 * Only the document id is read, member items are not hydrated: the filter runs before every
	 * action of the member controller.
	 */
	private function getDocumentsByMemberUids(array $uids): DocumentCollection
	{
		$memberUids = [];
		foreach ($uids as $value)
		{
			if (is_string($value) && $value !== '')
			{
				$memberUids[] = $value;
			}
		}

		return empty($memberUids)
			? new DocumentCollection()
			: $this->listDocumentsByIds($this->memberRepository->listDocumentIdsByMemberUids($memberUids))
		;
	}

	private function listDocumentsByIds(array $documentIds): DocumentCollection
	{
		return $this->documentRepository->listByIds(array_values(array_unique($documentIds)));
	}

	private function getTemplatesByIds(array $idsOrUids): TemplateCollection
	{
		$firstIdOrUid = $idsOrUids[array_key_first($idsOrUids)] ?? null;
		if (empty($firstIdOrUid))
		{
			return new TemplateCollection();
		}

		if (is_numeric($firstIdOrUid))
		{
			$ids = array_map(static fn(mixed $value) => (int)$value, $idsOrUids);

			return $this->templateRepository->getByIds($ids);
		}
		elseif (is_string($firstIdOrUid))
		{
			$uids = array_map(static fn(mixed $value) => (string)$value, $idsOrUids);

			return $this->templateRepository->listByUids($uids);
		}

		return new TemplateCollection();
	}

	private function getTemplateFoldersByIds(array $idsOrUids): TemplateFolderCollection
	{
		$firstIdOrUid = $idsOrUids[array_key_first($idsOrUids)] ?? null;
		if (empty($firstIdOrUid))
		{
			return new TemplateFolderCollection();
		}

		if (is_numeric($firstIdOrUid))
		{
			$ids = array_map(static fn(mixed $value) => (int)$value, $idsOrUids);

			return $this->templateFolderRepository->getByIds($ids);
		}

		return new TemplateFolderCollection();
	}

	private function getSafeFoldersByIds(array $idsOrUids): SafeFolderCollection
	{
		$firstIdOrUid = $idsOrUids[array_key_first($idsOrUids)] ?? null;
		if (empty($firstIdOrUid))
		{
			return new SafeFolderCollection();
		}

		if (is_numeric($firstIdOrUid))
		{
			$ids = array_map(static fn(mixed $value) => (int)$value, $idsOrUids);

			return $this->safeFolderRepository->getByIds($ids);
		}

		return new SafeFolderCollection();
	}

	private function getSignersListsByIds(array $idsOrUids): SignersListCollection
	{
		$firstIdOrUid = $idsOrUids[array_key_first($idsOrUids)] ?? null;

		if (empty($firstIdOrUid))
		{
			return new SignersListCollection();
		}

		if (is_numeric($firstIdOrUid))
		{
			$ids = array_map(static fn(mixed $value) => (int)$value, $idsOrUids);

			return $this->signersListService->listByIds($ids);
		}

		return new SignersListCollection();
	}

	/**
	 * @param string $accessPermission
	 * @param list<Main\Access\AccessibleItem> $accessibleItems
	 *
	 * @return bool
	 */
	private function checkPermission(string $accessPermission, array $accessibleItems): bool
	{
		if (empty($accessibleItems))
		{
			return $this->getAccessController()->check($accessPermission);
		}

		foreach ($accessibleItems as $accessibleItem)
		{
			if (!$this->getAccessController()->check($accessPermission, $accessibleItem))
			{
				return false;
			}
		}

		return true;
	}
}
