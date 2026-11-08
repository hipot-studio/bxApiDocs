<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2;

use Bitrix\Bizproc\Integration\UI\EntitySelector\AccessTemplateProvider;
use Bitrix\Bizproc\Internal\Access\AccessController;
use Bitrix\Bizproc\Internal\Access\OwnTemplatesOption;
use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\Access\RoleCollection;
use Bitrix\Bizproc\Internal\Service\DelegationScopeValidator;
use Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\Models\RightId;
use Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\Models\RightIdConverter;
use Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\Models\RightModel;
use Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\Structure\Entity\Template;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\Collection;
use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider;
use Bitrix\UI\AccessRights\V2\Control\Value\VariablesSource;
use Bitrix\UI\AccessRights\V2\Dto\AccessRightsBuilder\UserGroupModelDto;
use Bitrix\UI\AccessRights\V2\Options\RightSection\RightItem;

/**
 * ui.accessrights.v2 provider for the bizproc template ACL matrix. Pure UI layer: it never
 * persists (the write path is {@see \Bitrix\Bizproc\Public\Command\SaveRolePermissionsCommand}); it only
 * exposes the single template entity with its five rights and the roles with their matrix and access codes.
 *
 * Template variables are loaded only for the display path; the save/decode path does not query templates.
 *
 * Roles, their ids and their permission cells are one memoized snapshot shared by the variables and the role
 * models: the repository memoizes nothing, so a second getAllRoles() would be a second query, and the two
 * consumers run in either order (getOptions() builds the rights first, getData() the roles first).
 */
final class AccessRightsProvider implements Provider
{
	public const ENTITY_ID = 'bizproc-template';

	private ?array $entities = null;
	private ?array $variablesMap = null;
	private ?array $rolePermissions = null;
	private ?bool $lazyTemplatesUsable = null;
	private ?bool $templateAdmin = null;

	/**
	 * @param null|bool $ownSentinelExpressible answer of another render this snapshot has to obey, null to ask
	 *                                          the switches {@see self::isOwnSentinelExpressible()}
	 */
	public function __construct(
		private readonly ?int $userId = null,
		private readonly bool $withVariables = true,
		private readonly DelegationScopeValidator $scopeValidator = new DelegationScopeValidator(),
		private readonly string $uiValueSourceClass = VariablesSource::class,
		private readonly string $uiRightItemClass = RightItem::class,
		private readonly ?bool $ownSentinelExpressible = null,
	)
	{
	}

	public function loadEntities(): array
	{
		$this->entities ??= [
			new Template(
				$this->getVariablesMap(),
				$this->getAllSelectableByPermission(),
				$this->hasVariablesSource(),
			),
		];

		return $this->entities;
	}

	/**
	 * The matrix cell of every role, with one value withheld whenever the page has no element for it: the
	 * "own templates" sentinel gets an element of its own only in the source mode of the page and only while
	 * the portal switch of the value is on {@see self::ownTemplatesVariables()}. In the static mode the client
	 * collapses the pick into "all" by the number of variables, and a value it can neither show nor deselect
	 * still counts towards that number, so leaving it in would hand the role every future template one pick
	 * early; with the switch off the client has no element to sign the value with and captions the cell as an
	 * unrecognized template. Withheld from the page, not from the matrix: the granted value keeps acting, and
	 * a save from that page carries it over instead of deleting it
	 * ({@see \Bitrix\Bizproc\Internal\Service\RolePermissionService::withUnexpressedValues()}) - the two halves
	 * read the same pair of switches and have to keep reading it together.
	 *
	 * That pair decides it, not $withVariables: a caller reading the cells alone gets the very values the page
	 * of that portal would send.
	 *
	 * @return UserGroupModelDto[]
	 */
	public function loadUserGroupModels(): array
	{
		$snapshot = $this->loadRolePermissions();

		$roleIds = $snapshot['roleIds'];
		if (!$roleIds)
		{
			return [];
		}

		$touchable = array_fill_keys($roleIds, true);
		$permissionsByRole = $snapshot['permissionsByRole'];
		$accessCodesByRole = Container::getAccessRepository()->getAccessCodesByRoleIds($roleIds);
		$withholdOwnValue = !$this->isOwnSentinelExpressible();

		$models = [];
		foreach ($snapshot['roles'] as $role)
		{
			$roleId = (int)$role->getId();
			if (!isset($touchable[$roleId]))
			{
				continue;
			}

			$model = new UserGroupModelDto($roleId, (string)$role->getName());

			foreach ($accessCodesByRole[$roleId] ?? [] as $accessCode)
			{
				$model->addAccessCode($accessCode);
			}

			foreach ($permissionsByRole[$roleId] ?? [] as $permission)
			{
				if ($withholdOwnValue && (int)$permission['value'] === PermissionDictionary::VALUE_VARIATION_OWN)
				{
					continue;
				}

				$model->addAccessRightModel(
					new RightModel(self::ENTITY_ID, (string)$permission['id'], $permission['value']),
				);
			}

			$models[] = $model;
		}

		return $models;
	}

	private function resolveUserId(): int
	{
		return $this->userId ?? AccessController::getCurrent()->getUser()->getUserId();
	}

	public function getRightIdConverter(): RightIdConverter
	{
		return new RightIdConverter();
	}

	public function createRightModelByRightId(Provider\Models\RightId|RightId $id, mixed $value): ?RightModel
	{
		return new RightModel($id->entityId, $id->actionId, $value);
	}

	/**
	 * The single source of roles and role permissions for the display path: the role collection (the models
	 * need the names), the ids the user may touch and every permission cell of those roles.
	 *
	 * @return array{roles: RoleCollection, roleIds: int[], permissionsByRole: array<int, array<int, array{id: int, value: string}>>}
	 */
	private function loadRolePermissions(): array
	{
		if ($this->rolePermissions !== null)
		{
			return $this->rolePermissions;
		}

		$roles = Container::getAccessRepository()->getAllRoles();

		// A configuring user sees every role; all other users see none.
		$roleIds = $this->scopeValidator->filterTouchableRoleIds($this->resolveUserId(), $roles->getEntityIds());
		Collection::normalizeArrayValuesByInt($roleIds, false);

		$this->rolePermissions = [
			'roles' => $roles,
			'roleIds' => $roleIds,
			'permissionsByRole' => $this->loadPermissionsByRole($roleIds),
		];

		return $this->rolePermissions;
	}

	/**
	 * @param int[] $roleIds
	 * @return array<int, array<int, array{id: int, value: string}>> role id => permission cells
	 */
	private function loadPermissionsByRole(array $roleIds): array
	{
		$result = [];
		foreach (Container::getAccessRepository()->getPermissionsByRoleIds($roleIds) as $permission)
		{
			// The control layer speaks strings (template id, the "all" sentinel, toggler value);
			// the save path converts them back to int for the ORM.
			$result[(int)$permission->getRoleId()][] = [
				'id' => (int)$permission->getPermissionId(),
				'value' => (string)$permission->getValue(),
			];
		}

		return $result;
	}

	/**
	 * Per-multivariables-right flag whether the configuring user may pick "all".
	 *
	 * @return array<int, bool> permission id => user may pick "all"
	 */
	private function getAllSelectableByPermission(): array
	{
		if (!$this->withVariables || !$this->scopeValidator->canConfigure($this->resolveUserId()))
		{
			return [];
		}

		return array_fill_keys($this->getMultivariablesPermissionIds(), true);
	}

	/**
	 * @return array<int, array<int, array{id: int, title: string}>>
	 */
	private function getVariablesMap(): array
	{
		if ($this->variablesMap !== null)
		{
			return $this->variablesMap;
		}

		$this->variablesMap = [];
		if (!$this->withVariables || !$this->scopeValidator->canConfigure($this->resolveUserId()))
		{
			return $this->variablesMap;
		}

		$this->variablesMap = $this->isLazyTemplatesUsable()
			? $this->collectVariablesFromMatrix()
			: $this->allNodesTemplatesByPermission()
		;

		return $this->variablesMap;
	}

	/**
	 * The ids come from the values of the multivariables rights, the threshold cuts every right down to
	 * its first ITEMS_LIMIT ids, and one indexed query loads the titles of what is left. One query for every
	 * right and every role, never one per cell.
	 *
	 * The threshold runs before the query on purpose. Its cost then depends on the shown ids alone
	 * (ITEMS_LIMIT per right), not on how many templates the portal granted by name. And a single query keeps
	 * resolvability and title in one snapshot: a row deleted right now moves an id to the placeholder branch
	 * instead of dropping it out of both branches.
	 *
	 * @return array<int, array<int, array{id: int, title: string}>>
	 */
	private function collectVariablesFromMatrix(): array
	{
		// A right with no granted value keeps its key with the sentinel alone (or an empty list once the portal
		// switch is off): the client tells that state apart from "no source given".
		$variables = array_fill_keys($this->getMultivariablesPermissionIds(), $this->ownTemplatesVariables());

		$idsByPermission = [];
		foreach ($this->loadRolePermissions()['permissionsByRole'] as $cells)
		{
			foreach ($cells as $cell)
			{
				$permissionId = $cell['id'];
				if (!array_key_exists($permissionId, $variables))
				{
					continue;
				}

				$templateId = (int)$cell['value'];
				// a sentinel value ("all", "own"), 0 and any other noise are no template ids: the "own" option
				// is already an element of the right and counts towards neither the threshold nor the title query
				if ($templateId <= 0)
				{
					continue;
				}

				$idsByPermission[$permissionId][$templateId] = $templateId;
			}
		}

		$shownIdsByPermission = [];
		$shownIds = [];
		foreach ($idsByPermission as $permissionId => $idSet)
		{
			$ids = array_keys($idSet);
			rsort($ids);

			// The threshold counts every element of the right, titles and placeholders together: a role with
			// hundreds of unresolvable ids would otherwise bring the payload back to what it was.
			$shown = array_slice($ids, 0, AccessTemplateProvider::ITEMS_LIMIT);
			$shownIdsByPermission[$permissionId] = $shown;
			$shownIds += array_fill_keys($shown, true);
		}

		if (!$shownIds)
		{
			return $variables;
		}

		$titles = $this->selectTemplateTitles(array_keys($shownIds));
		$withPostfix = $this->isTemplateAdmin();

		foreach ($shownIdsByPermission as $permissionId => $ids)
		{
			foreach ($ids as $id)
			{
				if (isset($titles[$id]))
				{
					$postfix = $withPostfix ? AccessTemplateProvider::makeTitlePostfix($id) : '';
					$variables[$permissionId][] = ['id' => $id, 'title' => $titles[$id] . $postfix];
				}
				else
				{
					$variables[$permissionId][] = ['id' => $id, 'title' => self::makePlaceholderTitle($id)];
				}
			}
		}

		return $variables;
	}

	/**
	 * The "own templates" option every multivariables right opens with, or nothing while the portal switch is
	 * off. It is a scope value and no template, so it is built here instead of coming out of the matrix cells:
	 * that keeps it out of the ITEMS_LIMIT threshold and out of the title query, and it is offered on a right
	 * nobody was granted it on yet.
	 *
	 * Only the source mode has it. With the static list the client collapses the pick into "all" by the number
	 * of variables, and an extra element would break that collapsing in both directions; the cells follow the
	 * same pair of switches, so the value cannot be left counting where the element is missing
	 * {@see self::loadUserGroupModels()}.
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	private function ownTemplatesVariables(): array
	{
		// The same answer the cells obey, and not the switch alone: inside this branch the two are one and the
		// same reading, and under the answer of another render they have to stay one.
		if (!$this->isOwnSentinelExpressible())
		{
			return [];
		}

		return [[
			'id' => PermissionDictionary::VALUE_VARIATION_OWN,
			// one caption in both places: the same phrase names the item of the scope dialog
			'title' => AccessTemplateProvider::makeOwnTemplatesTitle(),
		]];
	}

	/**
	 * Every Nodes template of the portal in every multivariables right: the behaviour before the lazy scope,
	 * kept as the branch of the static mode.
	 *
	 * @return array<int, array<int, array{id: int, title: string}>>
	 */
	private function allNodesTemplatesByPermission(): array
	{
		$rows =
			AccessTemplateProvider::makeScopeQuery()
				->setSelect(['ID', 'NAME'])
				->setOrder(['ID' => 'ASC'])
				->exec()
				->fetchAll()
		;

		$templates = array_map(
			static fn (array $row): array => ['id' => (int)$row['ID'], 'title' => (string)$row['NAME']],
			$rows,
		);

		return array_fill_keys($this->getMultivariablesPermissionIds(), $templates);
	}

	/**
	 * Resolvability and title in one answer: a missing key means the id resolves to no template of the page
	 * scope, so the caller renders a placeholder for it.
	 *
	 * @param int[] $ids
	 * @return array<int, string> template id => name
	 */
	private function selectTemplateTitles(array $ids): array
	{
		if (!$ids)
		{
			return [];
		}

		$rows =
			AccessTemplateProvider::makeScopeQuery()
				->setSelect(['ID', 'NAME'])
				->whereIn('ID', $ids)
				->exec()
				->fetchAll()
		;

		$titles = [];
		foreach ($rows as $row)
		{
			$titles[(int)$row['ID']] = (string)$row['NAME'];
		}

		return $titles;
	}

	/**
	 * Caption of an id the page scope resolves to no template. The threshold leaves the ids past the first
	 * ITEMS_LIMIT without an element of their own, so the client captions those from the same phrase
	 * {@see self::makePlaceholderTitlePattern()}: one template never gets two different captions.
	 */
	private static function makePlaceholderTitle(int|string $id): string
	{
		return (string)Loc::getMessage('BIZPROC_ACCESS_RIGHTS_TEMPLATE_PLACEHOLDER', ['#ID#' => (string)$id]);
	}

	/**
	 * The same caption with the id left for the client to put in.
	 */
	public static function makePlaceholderTitlePattern(): string
	{
		return self::makePlaceholderTitle(RightItem::UNLOADED_VARIABLE_VALUE_PLACEHOLDER);
	}

	/**
	 * Same predicate as the items of the scope entity (API-01): a template administrator sees the id in the
	 * caption, so one template never gets two different captions before and after a save.
	 */
	private function isTemplateAdmin(): bool
	{
		$this->templateAdmin ??= (new \CBPWorkflowTemplateUser($this->resolveUserId()))->isAdmin();

		return $this->templateAdmin;
	}

	/**
	 * Whether the rights of the page carry the server-side value source, which is what tells the two modes of
	 * the page apart for everything built here: with the source the client neither counts the picked values
	 * against the variables nor collapses the pick on its own, so the sentinel of the scope may be both an
	 * element of the right and a value of a cell. The save path decodes values only, hence no source there.
	 */
	private function hasVariablesSource(): bool
	{
		return $this->withVariables && $this->isLazyTemplatesUsable();
	}

	/**
	 * Whether the page of THIS render can express the "own templates" sentinel at all: the value needs both the
	 * server-side value source of the page and the portal switch of the scope, and under either one unmet it is
	 * neither an element of a right nor a value of a cell.
	 *
	 * Public because the answer has to travel with the payload of the page. The save decides about carrying the
	 * value over by this very answer ({@see \Bitrix\Bizproc\Public\Provider\PermissionMatrixProvider::getOptions()}
	 * ships it, {@see \Bitrix\Bizproc\Internal\Service\RolePermissionService::withUnexpressedValues()} obeys it)
	 * instead of asking the two switches a second time. Asking twice made the pair of switches part of the
	 * request: one flipped between render and save either deleted a granted value or restored a deliberately
	 * deselected one, because the two halves of one rule answered differently.
	 *
	 * A snapshot built for a page that is already on screen is given that page's answer instead of asking the
	 * switches at all: the client replaces the matrix with the answer of a save but keeps the parameters of its
	 * own render, so the two would part ways for the rest of the session
	 * ({@see \Bitrix\Bizproc\Public\Provider\PermissionMatrixProvider::getDataForSaveResponse()}).
	 */
	public function isOwnSentinelExpressible(): bool
	{
		return $this->ownSentinelExpressible === null
			? ($this->isLazyTemplatesUsable() && OwnTemplatesOption::isEnabled())
			: $this->carriesExpressibleRender()
		;
	}

	/**
	 * Whether the answer carried into this snapshot may be believed. The mode of a render travels with its
	 * payload, the ui contract does not: the answer arrives as a request parameter, so a claim of "expressible"
	 * against a ui that predates the value source would build the page with a class that is on no autoloader.
	 * An honest page loses nothing by the probe - the classes it rendered with are still loaded - while a forged
	 * claim falls back to the static mode instead of a fatal.
	 */
	private function carriesExpressibleRender(): bool
	{
		return $this->ownSentinelExpressible === true
			&& LazyTemplatesOption::isContractAvailable($this->uiValueSourceClass, $this->uiRightItemClass)
		;
	}

	/**
	 * The option and the ui contract are two independent reasons to stay on the static branch: either one
	 * keeps the static list, and neither is a condition of the other. bizproc may run against a ui that predates
	 * the value source, and then the page stays as it was instead of calling an API that is not there. Silently,
	 * because for the user that is simply the page it had before.
	 */
	private function isLazyTemplatesUsable(): bool
	{
		// An answer of another render saying "expressible" carries this one: the answer is the conjunction of the
		// mode and the portal switch, so its true side means that render had the value source. Without this the
		// snapshot would follow the render for the cells and the switches for the elements - and a mode switched
		// off in between would leave the value in a cell the static page names no element for, shifting the
		// threshold the client collapses the pick by. A carried answer the ui contract does not back is believed
		// by neither half {@see self::carriesExpressibleRender()}.
		if ($this->carriesExpressibleRender())
		{
			return true;
		}

		$this->lazyTemplatesUsable ??= LazyTemplatesOption::isUsable(
			$this->uiValueSourceClass,
			$this->uiRightItemClass,
		);

		return $this->lazyTemplatesUsable;
	}

	/**
	 * @return int[]
	 */
	private function getMultivariablesPermissionIds(): array
	{
		$ids = [];
		foreach (array_keys(PermissionDictionary::getList()) as $permissionId)
		{
			$descriptor = PermissionDictionary::getPermission((string)$permissionId);
			if (($descriptor['type'] ?? null) === PermissionDictionary::TYPE_MULTIVARIABLES)
			{
				$ids[] = (int)$permissionId;
			}
		}

		return $ids;
	}
}
