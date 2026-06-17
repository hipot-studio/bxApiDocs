<?php

namespace Bitrix\HumanResources\Internals\Entity\Provider\UI;

use Bitrix\HumanResources\Internals\Enum\Provider\UI\DepartmentProviderAvatarMode;
use Bitrix\HumanResources\Internals\Enum\Provider\UI\DepartmentProviderSelectMode;
use Bitrix\HumanResources\Internals\Enum\Provider\UI\DepartmentProviderTagStyleMode;
use Bitrix\HumanResources\Type\NodeMemberRole;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\Socialnetwork\Integration\UI\EntitySelector\UserProvider;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Loader;

class DepartmentProviderOptions extends BaseProviderOptions
{
	public readonly DepartmentProviderTagStyleMode $tagStyle;
	public readonly DepartmentProviderAvatarMode $avatarMode;
	public readonly DepartmentProviderSelectMode $selectMode;
	public readonly bool $shouldCountSubdepartments;
	public readonly bool $allowOnlyUserDepartments;
	public readonly bool $onlyManagedHierarchy;
	/** @var list<NodeMemberRole> */
	public readonly array $managedHierarchyRoles;
	public readonly bool $allowSelectRootDepartment;
	public readonly bool $allowFlatDepartments;
	public readonly bool $fillDepartmentsTab;
	public readonly bool $nodeActiveFilter;
	public readonly bool $shouldCountUsers;
	public readonly bool $useMultipleTabs;
	public readonly bool $fillRecentTab;
	public readonly bool $restricted;
	public readonly bool $showIcons;
	public readonly bool $showDepartmentCreationFooter;
	public readonly bool $showDepartmentCreationFooterInRecentTab;
	public readonly bool $isForSearch;
	public readonly bool $isFlatMode;
	public readonly array $userOptions;
	public readonly int $depthLevel;

	/**
	 * @throws LoaderException
	 * @throws ArgumentException
	 */
	public function __construct(array $rawOptions = [])
	{
		parent::__construct($rawOptions);

		$this->initNodeActiveFilter($rawOptions);
		$this->initSelectMode($rawOptions);
		$this->initAllowFlatDepartments($rawOptions);
		$this->initAllowOnlyUserDepartments($rawOptions);
		$this->initOnlyManagedHierarchy($rawOptions);
		$this->initManagedHierarchyRoles($rawOptions);
		$this->initAllowSelectRootDepartment($rawOptions);
		$this->initUserOptions($rawOptions);
		$this->initFillDepartmentsTab($rawOptions);
		$this->initFillRecentTab($rawOptions);
		$this->initDepthLevel($rawOptions);
		$this->initShouldCountSubdepartments($rawOptions);
		$this->initShouldCountUsers($rawOptions);
		$this->initForSearch($rawOptions);
		$this->initFlatMode($rawOptions);
		$this->initUseMultipleTabs($rawOptions);
		$this->initVisualOptions($rawOptions);
		$this->initShowDepartmentCreationFooter($rawOptions);
		$this->initShowDepartmentCreationFooterInRecentTab($rawOptions);

		$this->assertOnlyManagedHierarchyCompatibility();
	}

	/**
	 * Guards against option combinations that onlyManagedHierarchy is not designed for.
	 *
	 * The managed-hierarchy path bypasses fetchNodes and its per-type limits / flat-mode /
	 * multi-tab / CreateAction branching. Silently ignoring those knobs would produce output
	 * that diverges from the regular mode without any warning, so we fail fast instead.
	 *
	 * The pair (onlyManagedHierarchy + allowOnlyUserDepartments) is also rejected: both
	 * flags narrow the visible structure, but by different criteria (managing role vs.
	 * membership). Letting one silently win leaves consumer-side bugs hard to spot.
	 *
	 * @throws ArgumentException
	 */
	private function assertOnlyManagedHierarchyCompatibility(): void
	{
		if (!$this->onlyManagedHierarchy)
		{
			return;
		}

		$conflicts = [];
		if ($this->isFlatMode)
		{
			$conflicts[] = 'flatMode';
		}
		if ($this->useMultipleTabs)
		{
			$conflicts[] = 'useMultipleTabs';
		}
		if ($this->structureAction === StructureAction::CreateAction)
		{
			$conflicts[] = 'structureAction=create';
		}
		if ($this->allowOnlyUserDepartments)
		{
			$conflicts[] = 'allowOnlyUserDepartments';
		}

		if (!empty($conflicts))
		{
			throw new ArgumentException(
				'onlyManagedHierarchy is not compatible with: ' . implode(', ', $conflicts) . '.',
			);
		}
	}

	private function initNodeActiveFilter(array $options): void
	{
		$this->nodeActiveFilter = isset($options['activeNodeFilter']) && is_bool($options['activeNodeFilter'])
			? $options['activeNodeFilter']
			: true
		;
	}

	private function initSelectMode(array $options): void
	{
		$this->selectMode =
			DepartmentProviderSelectMode::tryFrom($options['selectMode'])
			?? DepartmentProviderSelectMode::UsersOnly
		;
	}

	private function initAllowFlatDepartments(array $options): void
	{
		$this->allowFlatDepartments =
			isset($options['allowFlatDepartments'])
			&& $options['allowFlatDepartments'] === true
		;
	}

	private function initAllowOnlyUserDepartments(array $options): void
	{
		$this->allowOnlyUserDepartments =
			isset($options['allowOnlyUserDepartments'])
			&& $options['allowOnlyUserDepartments'] === true
		;
	}

	/**
	 * Enables the "managed hierarchy only" mode.
	 *
	 * When enabled, the provider narrows the visible structure to the subtrees of nodes where
	 * the current user holds one of {@see $managedHierarchyRoles} (by default: HEAD /
	 * DEPUTY_HEAD and their team equivalents). This is used by consumers that must hide
	 * departments the current user does not manage (tree, search, recent users).
	 */
	private function initOnlyManagedHierarchy(array $options): void
	{
		$this->onlyManagedHierarchy =
			isset($options['onlyManagedHierarchy'])
			&& $options['onlyManagedHierarchy'] === true
		;
	}

	/**
	 * List of roles that count as "managing" in the managed-hierarchy mode.
	 *
	 * Consumer may narrow or widen the set (e.g. only HEAD, without deputies) via
	 * {@code managedHierarchyRoles}, as a list of NodeMemberRole XML IDs
	 * ("MEMBER_HEAD", "MEMBER_DEPUTY_HEAD", etc).
	 *
	 * An empty / missing / fully-invalid list falls back to the default set of four
	 * managing roles so that getManagedHierarchyNodes() never degrades to "any node the
	 * user belongs to" (which would happen with an empty RoleFilter).
	 */
	private function initManagedHierarchyRoles(array $options): void
	{
		$default = [
			NodeMemberRole::Head,
			NodeMemberRole::DeputyHead,
			NodeMemberRole::TeamHead,
			NodeMemberRole::TeamDeputyHead,
		];

		$raw = $options['managedHierarchyRoles'] ?? null;
		if (!is_array($raw) || empty($raw))
		{
			$this->managedHierarchyRoles = $default;

			return;
		}

		$xmlIds = array_values(array_filter($raw, 'is_string'));
		$roles = NodeMemberRole::fromXmlIds($xmlIds);

		$this->managedHierarchyRoles = empty($roles) ? $default : $roles;
	}

	private function initAllowSelectRootDepartment(array $options): void
	{
		$this->allowSelectRootDepartment = is_bool($options['allowSelectRootDepartment'] ?? null)
			? $options['allowSelectRootDepartment']
			: $this->selectMode === DepartmentProviderSelectMode::DepartmentsOnly
		;
	}

	/**
	 * @throws LoaderException
	 */
	private function initUserOptions(array $options): void
	{
		if (
			isset($options['userOptions'])
			&& is_array($options['userOptions'])
			&& Loader::includeModule('socialnetwork')
		)
		{
			$userProvider = new UserProvider($options['userOptions']);
			$this->userOptions = $userProvider->getOptions();
		}
		else
		{
			$this->userOptions = [];
		}
	}

	private function initFillDepartmentsTab(array $options): void
	{
		$this->fillDepartmentsTab = is_bool($options['fillDepartmentsTab'] ?? null)
			? $options['fillDepartmentsTab']
			: true
		;
	}

	private function initFillRecentTab(array $options): void
	{
		if (isset($options['fillRecentTab']) && is_bool($options['fillRecentTab']))
		{
			$this->fillRecentTab =
				$options['fillRecentTab']
				&& ($this->selectMode === DepartmentProviderSelectMode::DepartmentsOnly)
			;
		}
		else
		{
			$this->fillRecentTab = false;
		}
	}

	private function initDepthLevel(array $options): void
	{
		if (
			isset($options['depthLevel'])
			&& is_int($options['depthLevel'])
			&& $this->fillRecentTab
		)
		{
			$this->depthLevel = $options['depthLevel'];
		}
		else
		{
			$this->depthLevel = 1;
		}
	}

	private function initShouldCountSubdepartments(array $options): void
	{
		$allowedModes = [
			DepartmentProviderSelectMode::DepartmentsOnly,
			DepartmentProviderSelectMode::UsersAndDepartments,
		];

		if (
			isset($options['shouldCountSubdepartments'])
			&& is_bool($options['shouldCountSubdepartments'])
		)
		{
			$this->shouldCountSubdepartments =
				$options['shouldCountSubdepartments']
				&& in_array($this->selectMode, $allowedModes, true)
			;
		}
		else
		{
			$this->shouldCountSubdepartments = false;
		}
	}

	private function initShouldCountUsers(array $options): void
	{
		$allowedModes = [
			DepartmentProviderSelectMode::UsersOnly,
			DepartmentProviderSelectMode::UsersAndDepartments,
		];

		if (
			isset($options['shouldCountUsers'])
			&& is_bool($options['shouldCountUsers'])
		)
		{
			$this->shouldCountUsers =
				$options['shouldCountUsers']
				&& in_array($this->selectMode, $allowedModes, true)
			;
		}
		else
		{
			$this->shouldCountUsers = false;
		}
	}

	private function initForSearch(array $options): void
	{
		$this->isForSearch = is_bool($options['forSearch'] ?? null)
			? $options['forSearch']
			: false
		;
	}

	private function initFlatMode(array $options): void
	{
		$this->isFlatMode = is_bool($options['flatMode'] ?? null)
			? $options['flatMode']
			: false
		;
	}

	private function initUseMultipleTabs(array $options): void
	{
		$this->useMultipleTabs =
			isset($options['useMultipleTabs'])
			&& $options['useMultipleTabs']
		;
	}

	private function initVisualOptions(array $options): void
	{
		$visualOptions = $options['visual'] ?? [];

		$this->avatarMode =
			DepartmentProviderAvatarMode::tryFrom($visualOptions['avatarMode'])
			?? DepartmentProviderAvatarMode::Both
		;

		$this->tagStyle =
			DepartmentProviderTagStyleMode::tryFrom($visualOptions['tagStyle'])
			?? DepartmentProviderTagStyleMode::Default
		;

		$this->showIcons = (bool)($visualOptions['showIcons'] ?? true);
	}

	private function initShowDepartmentCreationFooter(array $options): void
	{
		$this->showDepartmentCreationFooter =
			isset($options['showDepartmentCreationFooter'])
			&& $options['showDepartmentCreationFooter'] === true
		;
	}

	private function initShowDepartmentCreationFooterInRecentTab(array $options): void
	{
		$this->showDepartmentCreationFooterInRecentTab =
			isset($options['showDepartmentCreationFooterInRecentTab'])
			&& $options['showDepartmentCreationFooterInRecentTab'] === true
		;
	}
}
