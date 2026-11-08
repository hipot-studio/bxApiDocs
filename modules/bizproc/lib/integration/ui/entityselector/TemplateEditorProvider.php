<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Integration\UI\EntitySelector;

use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Access\Service\TemplateAuthorshipProvider;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\Intranet;
use Bitrix\Main\Entity\Query;
use Bitrix\Main\EO_User_Collection;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\UserAccessTable;
use Bitrix\Main\UserTable;
use Bitrix\Socialnetwork\Integration\UI\EntitySelector\UserProvider;

// The parent provider lives in socialnetwork; without the module this class cannot be built at all.
Loader::includeModule('socialnetwork');

/**
 * Mention hint of the template notes: the standard user selector narrowed to the editors of one template.
 *
 * Registered as {@see self::SUBSTITUTE_ENTITY_ID} substituting `user`, so the items stay `user` items and a
 * mention in the text keeps the `user` tag (TPL-01). The client declares the entity as `user`, passes this id
 * as `substituteEntityId` and the option `{templateId: int}`.
 *
 * Three gates come before any selection (ALG-01): the feature flag, the current user being an employee of the
 * portal, and their right on that template - the answer is a list of employees, and a caller outside that
 * circle must not learn it. Then the candidates: the roles granting EDIT or PUBLISH on this template or on all
 * templates, resolved to users through the access code table, the group of the portal administrators, whom no
 * role points at, plus the author of the template while some role carries the "own templates" sentinel - such a
 * role points at nobody through an access code either. The access code table is rebuilt lazily and lags in both
 * directions, so it only narrows the candidates: every candidate of the page is then asked directly
 * {@see TemplateAccessService::canEditBatch()}.
 *
 * Only employees are offered, exactly as only they are admitted to the notes at all: the narrowing itself is
 * the option of the parent, pinned here rather than taken from the client.
 *
 * A denial is an empty result and never an error.
 */
class TemplateEditorProvider extends UserProvider
{
	/**
	 * Id this provider is registered under, and the value the client passes as `substituteEntityId`. Not
	 * ENTITY_ID: that constant is the entity of the items and stays `user` - this provider substitutes the user
	 * provider rather than introducing an entity of its own (TPL-01), which is what keeps the mention tag and
	 * the pool of recent items shared with the ordinary user selectors.
	 */
	public const SUBSTITUTE_ENTITY_ID = 'bizproc-template-editor';

	/**
	 * Page of candidates a single request may check. Narrower than the 100 of the parent: every candidate of
	 * the page costs a direct permission check, and the client renders far fewer matches than that anyway.
	 */
	protected const SEARCH_LIMIT = 50;

	/**
	 * The rights an editor of a template holds: publish dominates edit ({@see TemplateAccessService::canEdit()}),
	 * so the candidates are collected over both - the same union the list filter of the module uses.
	 */
	private const EDITOR_PERMISSIONS = [
		PermissionDictionary::BIZPROC_TEMPLATE_EDIT,
		PermissionDictionary::BIZPROC_TEMPLATE_PUBLISH,
	];

	/**
	 * Access code of the administrators group - the source of the right no role carries: an administrator passes
	 * the check by a bypass of the controller ({@see \Bitrix\Bizproc\Internal\Access\AccessController::isAdmin()})
	 * and is bound to no role at all, so the reverse lookup over the roles cannot reach them. The group is the
	 * same one that bypass reads (`in_array(1, $userGroups)` of the user model of the framework), and membership
	 * in it lands in the access code table as a plain code.
	 */
	private const ADMIN_GROUP_ACCESS_CODE = 'G1';

	/**
	 * Options of the parent deciding whether only employees are selected. Pinned instead of read from the client:
	 * the parent applies its employee filter on one branch of a chain, and any of these values coming from the
	 * outside would route the query around that branch ({@see UserProvider::getQuery()}).
	 */
	private const EMPLOYEES_ONLY_OPTIONS = [
		'intranetUsersOnly' => true,
		'extranetUsersOnly' => false,
		'emailUsersOnly' => false,
		'networkUsersOnly' => false,
		'activeUsers' => true,
		'departmentId' => null,
	];

	/**
	 * Chat access codes take no part in the reverse selection, exactly as they take none in the forward one
	 * ({@see \Bitrix\Main\Access\User\UserModel::buildAccessCodes()}): a role relation never carries such a code,
	 * while a portal holds a great many rows of them.
	 */
	private const CHAT_ACCESS_PROVIDER = 'imchat';

	protected function prepareOptions(array $options = []): void
	{
		parent::prepareOptions($options);

		$this->options['templateId'] = (int)($options['templateId'] ?? 0);

		// The parent narrows the visibility of the selector by this id and the gate below reads the rights of it:
		// pinned to one source, because two of them would answer one request for two different users. The value
		// is the current user of the request either way - it is set here, and never taken from the client.
		$this->options['currentUserId'] = static::getCurrentUserId();
	}

	/**
	 * All three gates and the narrowing live here, in the single query source every output path of the parent
	 * goes through (the first page, the recent items, the search, the resolution of ids): a gate placed on one of
	 * those paths alone would leave the others open.
	 */
	protected static function getQuery(array $options = []): Query
	{
		if (!static::isFeatureEnabled())
		{
			return static::getEmptyQuery();
		}

		$templateId = (int)($options['templateId'] ?? 0);
		if ($templateId <= 0)
		{
			return static::getEmptyQuery();
		}

		// Both gates are read off the current user of the request and never off the options: a foreign id passed
		// in would decide the gates of somebody else.
		$currentUserId = static::getCurrentUserId();
		if (!static::isEmployee($currentUserId))
		{
			return static::getEmptyQuery();
		}

		if (!static::accessService()->canEdit($templateId, $currentUserId))
		{
			return static::getEmptyQuery();
		}

		$accessCodes = static::getCandidateAccessCodes($templateId);
		$ownAuthorId = static::getOwnScopeAuthorId($templateId);

		return parent::getQuery(static::pinEmployeesOnly($options))
			->where(static::makeCandidateFilter($accessCodes, $ownAuthorId))
		;
	}

	/**
	 * The page of candidates, each one asked directly. Every output path of the parent assembles its items
	 * here, so this is the one place the check cannot be walked around - the recent items in particular, whose
	 * pool is keyed by the entity and therefore shared with the ordinary user selectors: an employee this hint
	 * no longer offers may still sit in it, and dropping them is the job of the provider and not of the client.
	 */
	public function makeUserItems(EO_User_Collection $users, array $options = []): array
	{
		return parent::makeUserItems($this->filterEditors($users), $options);
	}

	/**
	 * The flag of the note discussions belongs to the editor module, and it closes the whole server surface of
	 * the feature. That module is optional for bizproc, so it is asked through the Loader and through its own
	 * getter instead of reading its option here - the mirror of the way it asks bizproc about the flags bizproc
	 * owns ({@see Feature::isPilotPublicationAvailable()}).
	 */
	protected static function isFeatureEnabled(): bool
	{
		return Loader::includeModule('bizprocdesigner') && Feature::instance()->areTemplateNotesAvailable();
	}

	protected static function accessService(): TemplateAccessService
	{
		return new TemplateAccessService();
	}

	/**
	 * Whether the user belongs to the circle the notes are open to at all. Asked exactly as the note actions ask
	 * it ({@see \Bitrix\BizprocDesigner\Infrastructure\Controller\TemplateNote::getDefaultPreFilters()}), so the
	 * hint and the actions cannot answer one portal differently.
	 *
	 * A portal without the intranet module knows no such circle: it has no employees to tell from external and
	 * mail users, and refusing everyone there would leave the mentions of the notes unusable. The module is
	 * optional for bizproc, so its absence is a state to answer and not an error - the same reading the action
	 * filter of the notes has, where the filter is added only while the module is there. Mail and network users
	 * stay out of the selection on such a portal regardless: the parent selects real users alone
	 * ({@see \Bitrix\Main\UserTable::getExternalUserTypes()}).
	 */
	protected static function isEmployee(int $userId): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			return true;
		}

		return $userId > 0 && Intranet\Util::isIntranetUser($userId);
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private static function pinEmployeesOnly(array $options): array
	{
		return array_merge($options, self::EMPLOYEES_ONLY_OPTIONS);
	}

	private function filterEditors(EO_User_Collection $users): EO_User_Collection
	{
		$editors = new EO_User_Collection();

		$templateId = (int)$this->getOption('templateId', 0);
		if ($templateId <= 0)
		{
			return $editors;
		}

		$userIds = [];
		foreach ($users as $user)
		{
			$userIds[] = (int)$user->getId();
		}

		$rights = static::accessService()->canEditBatch($templateId, $userIds);
		foreach ($users as $user)
		{
			if ($rights[(int)$user->getId()] ?? false)
			{
				$editors->add($user);
			}
		}

		return $editors;
	}

	/**
	 * A subquery and not a join: a user carries a row per access code, and a join would multiply the page of the
	 * selector by their number.
	 *
	 * @param string[] $accessCodes
	 */
	private static function makeCandidateFilter(array $accessCodes, int $ownAuthorId): ConditionTree
	{
		$filter = Query::filter()->logic('or');

		if ($accessCodes)
		{
			$holders = UserAccessTable::query()
				->setSelect(['USER_ID'])
				->whereIn('ACCESS_CODE', $accessCodes)
				->whereNot('PROVIDER_ID', self::CHAT_ACCESS_PROVIDER)
			;

			$filter->whereIn('ID', $holders);
		}

		if ($ownAuthorId > 0)
		{
			$filter->where('ID', $ownAuthorId);
		}

		return $filter;
	}

	/**
	 * Access codes the candidates are narrowed by: those of the roles granting this template, plus the group of
	 * the portal administrators. Both are candidates and nothing more - an administrator holds the right by the
	 * bypass of the controller, and a member of the group who somehow does not is dropped by the direct check
	 * like any other candidate.
	 *
	 * @return string[]
	 */
	private static function getCandidateAccessCodes(int $templateId): array
	{
		return array_merge([self::ADMIN_GROUP_ACCESS_CODE], static::getEditorAccessCodes($templateId));
	}

	/**
	 * Access codes of the roles granting the template: the reverse direction of the effective permissions, and
	 * the only way from a template to the people who hold the right by a role.
	 *
	 * @return string[]
	 */
	private static function getEditorAccessCodes(int $templateId): array
	{
		$repository = Container::getAccessRepository();
		if ($repository === null)
		{
			return [];
		}

		$roleIds = $repository->getRoleIdsByPermissionValues(
			self::EDITOR_PERMISSIONS,
			[$templateId, PermissionDictionary::VALUE_VARIATION_ALL],
		);
		if (!$roleIds)
		{
			return [];
		}

		$codes = [];
		foreach ($repository->getAccessCodesByRoleIds($roleIds) as $roleCodes)
		{
			foreach ($roleCodes as $code)
			{
				$codes[$code] = $code;
			}
		}

		return array_values($codes);
	}

	/**
	 * Author of the template while a role grants the "own templates" scope: that scope gives the right to the
	 * author alone and no access code of the role points at them. The row is read through the own-scope condition
	 * shared with the check side, so a template outside that scope (a system, a non-Nodes or a scenario made one)
	 * yields no author at all. Whether the portal switch of the sentinel is on is not read here: with it off the
	 * sentinel is already subtracted from the effective scope, and the direct check drops the author again.
	 */
	private static function getOwnScopeAuthorId(int $templateId): int
	{
		$repository = Container::getAccessRepository();
		if ($repository === null)
		{
			return 0;
		}

		$ownRoleIds = $repository->getRoleIdsByPermissionValues(
			self::EDITOR_PERMISSIONS,
			[PermissionDictionary::VALUE_VARIATION_OWN],
		);
		if (!$ownRoleIds)
		{
			return 0;
		}

		$row = WorkflowTemplateTable::query()
			->setSelect(['CREATED_BY'])
			->where('ID', $templateId)
			->where(TemplateAuthorshipProvider::ownScopeCondition())
			->fetch()
		;

		return $row ? (int)$row['CREATED_BY'] : 0;
	}

	/** Selects nobody, so a denial and an empty candidate set answer with the same shape as a normal query. */
	private static function getEmptyQuery(): Query
	{
		return UserTable::query()->setSelect(['ID'])->where('ID', 0);
	}
}
