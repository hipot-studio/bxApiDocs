<?php

namespace Bitrix\Bizproc\Internal\Repository\WorkflowTemplate;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateSection;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\Query\LaunchedCopyQuery;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateSectionTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable;
use Bitrix\Bizproc\WorkflowTemplateTable;

class AiAgentRepository
{
	/**
	 * Ids per warmup query, so a caller holding an unusually long selection does not turn into a single
	 * unbounded `IN`. Mirrors {@see \Bitrix\Bizproc\Internal\Access\Service\TemplateAuthorshipProvider}.
	 */
	private const TRANSFER_WARMUP_CHUNK_SIZE = 500;

	/**
	 * Warm state of {@see self::warmupTransferRights()}: the started launched copy condition of
	 * canTransferOwnership(), read for a whole page of ids at once.
	 *
	 * @var array<int, bool> templateId => is a started launched copy; a key means the warmup covered the id
	 */
	private array $startedLaunchedCopies = [];

	/**
	 * @var array<int, int> templateId => ACTIVATED_BY of a started launched copy; absent when the copy
	 *  carries no owner, which is why it is a map of its own and not a null in the one above
	 */
	private array $startedLaunchedCopyOwners = [];

	public function __construct(
		private readonly TemplateAccessService $templateAccessService = new TemplateAccessService(),
	) {}

	/**
	 * @param list<int> $ids
	 *
	 * @return list<int>
	 */
	public function getOnlyExistAndAllowedToDeleteTemplateIds(
		array $ids,
		bool $isUserAdmin,
		int $userIdDeleteBy,
		bool $ignoreOwner = false,
	): array
	{
		$ids = array_filter(array_map(static fn($id) => (int)$id, $ids));
		if (empty($ids))
		{
			return [];
		}

		$query = WorkflowTemplateTable::query()
			->whereIn('ID', $ids)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('SYSTEM_CODE')
			->setSelect(['ID'])
		;

		if (!$isUserAdmin && !$ignoreOwner)
		{
			$query
				->where('ACTIVATED_BY', $userIdDeleteBy)
			;
		}

		$rows = $query->fetchAll();

		return array_map(
			static fn($id) => (int)$id,
			array_column($rows, 'ID'),
		);
	}

	/**
	 * Single source of truth ownership predicate for launched AI-agent templates.
	 * Mirrors the grid/delete criterion: launched copy (TYPE=Nodes, SYSTEM_CODE IS NULL),
	 * current user is the owner (ACTIVATED_BY) or an admin, optionally already started.
	 */
	public function canManageLaunchedTemplate(
		int $templateId,
		int $userId,
		bool $isUserAdmin,
		bool $requireStarted = false,
	): bool
	{
		if ($templateId <= 0)
		{
			return false;
		}

		$query = WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('SYSTEM_CODE')
			->setSelect(['ID'])
			->setLimit(1)
		;

		if (!$isUserAdmin)
		{
			$query->where('ACTIVATED_BY', $userId);
		}

		if ($requireStarted)
		{
			$query->whereNotNull('ACTIVATED_AT');
		}

		return !empty($query->fetch());
	}

	/**
	 * Whether $userId may hand a launched AI-agent copy over to somebody else.
	 *
	 * Three branches answer yes: the owner of the copy and a portal administrator, both already covered
	 * by canManageLaunchedTemplate() with requireStarted, and a holder of the right to edit this very
	 * template. The edit right is the same one the launch predicate of the section asks for
	 * (LaunchableTemplateResolver), so a colleague who may rebuild the agent may also hand it over.
	 *
	 * A system template of the catalogue never passes: the started launched copy condition
	 * (TYPE=Nodes, SYSTEM_CODE IS NULL, ACTIVATED_AT set) rejects it by itself.
	 *
	 * The recipient takes no part here. Whether the new owner may receive the agent is a separate check
	 * of the transfer operation.
	 */
	public function canTransferOwnership(int $templateId, int $userId, bool $isUserAdmin): bool
	{
		// Only a positive id is ever warmed, so a non-positive one falls through to the cold path below.
		if (array_key_exists($templateId, $this->startedLaunchedCopies))
		{
			return $this->canTransferWarmedUpOwnership($templateId, $userId, $isUserAdmin);
		}

		if ($this->canManageLaunchedTemplate($templateId, $userId, $isUserAdmin, requireStarted: true))
		{
			return true;
		}

		// The edit right says nothing about the template being a started launched copy, so the owner
		// filter is bypassed only to confirm that it is one before the ACL is asked at all.
		return $this->canManageLaunchedTemplate($templateId, 0, isUserAdmin: true, requireStarted: true)
			&& $this->templateAccessService->canEdit($templateId, $userId)
		;
	}

	/**
	 * Batch warmup of canTransferOwnership() for a caller that already holds the whole list of ids (a page
	 * of the AI-agents grid). One query answers the started launched copy condition and the owner of every
	 * id at once, one more warms the authorship of those ids the ACL branch can actually reach, and the
	 * per-row predicate then costs no query at all instead of the one to three it makes on its own (grid
	 * hot path, NFR P95).
	 *
	 * An optimization, never a gate: every check stays correct without the warmup, only more expensive.
	 *
	 * The started launched copy condition and the owner of an id do not depend on who asks, so they are read
	 * once per id; the ACL branch does, so its warmup is asked for every id of the page on every call. Two
	 * users asking about the same page in one request each get their own authorship warmed.
	 *
	 * @param array<int, int|string> $templateIds
	 * @param int $userId Acting user whose authorship is warmed - the same one the checks are asked about.
	 */
	public function warmupTransferRights(array $templateIds, int $userId): void
	{
		$ids = [];
		foreach ($templateIds as $templateId)
		{
			$templateId = (int)$templateId;
			if ($templateId > 0)
			{
				$ids[$templateId] = $templateId;
			}
		}

		if (!$ids)
		{
			return;
		}

		$missing = array_diff_key($ids, $this->startedLaunchedCopies);

		foreach (array_chunk(array_values($missing), self::TRANSFER_WARMUP_CHUNK_SIZE) as $chunk)
		{
			// A refusal is warmed as well: an id that is not a started launched copy is asked about exactly
			// as often as one that is, and it is the refusal that would otherwise go to the database.
			foreach ($chunk as $templateId)
			{
				$this->startedLaunchedCopies[$templateId] = false;
			}

			$rows = WorkflowTemplateTable::query()
				->setSelect(['ID', 'ACTIVATED_BY'])
				->whereIn('ID', $chunk)
				->where('TYPE', WorkflowTemplateType::Nodes->value)
				->whereNull('SYSTEM_CODE')
				->whereNotNull('ACTIVATED_AT')
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$templateId = (int)$row['ID'];
				$this->startedLaunchedCopies[$templateId] = true;

				if ($row['ACTIVATED_BY'] !== null)
				{
					$this->startedLaunchedCopyOwners[$templateId] = (int)$row['ACTIVATED_BY'];
				}
			}
		}

		$aclIds = $this->idsLeftToTheAcl($ids, $userId);
		if ($aclIds)
		{
			// The ACL branch of the predicate asks the edit right, so that is the right whose authorship is
			// warmed: the facade skips the batch by itself whenever a check of it cannot reach authorship.
			$this->templateAccessService->warmupAuthorshipForPermission(
				$aclIds,
				PermissionDictionary::BIZPROC_TEMPLATE_EDIT,
				$userId,
			);
		}
	}

	/**
	 * Ids of the page whose right this user cannot have answered before the ACL branch: a warm refusal and
	 * a copy this user owns are decided by the two branches ahead of it
	 * {@see self::canTransferWarmedUpOwnership()}, so warming their authorship would be a query nobody
	 * reads. The administrator, whose every row answers on the flag, is left to the facade - the flag is
	 * not part of the warm state.
	 *
	 * @param array<int, int> $templateIds
	 *
	 * @return list<int>
	 */
	private function idsLeftToTheAcl(array $templateIds, int $userId): array
	{
		$ids = [];
		foreach ($templateIds as $templateId)
		{
			if (
				$this->startedLaunchedCopies[$templateId]
				&& ($this->startedLaunchedCopyOwners[$templateId] ?? null) !== $userId
			)
			{
				$ids[] = $templateId;
			}
		}

		return $ids;
	}

	/**
	 * The same three branches as canTransferOwnership(), decided against the page the warmup read instead
	 * of two queries per row: an id that is not a started launched copy is refused by both branches, the
	 * owner and an admin pass by the first one, and everybody else is left to the ACL, whose authorship
	 * the warmup covered too.
	 */
	private function canTransferWarmedUpOwnership(int $templateId, int $userId, bool $isUserAdmin): bool
	{
		if (!$this->startedLaunchedCopies[$templateId])
		{
			return false;
		}

		return $isUserAdmin
			|| ($this->startedLaunchedCopyOwners[$templateId] ?? null) === $userId
			|| $this->templateAccessService->canEdit($templateId, $userId)
		;
	}

	/**
	 * Owner (ACTIVATED_BY) of a launched AI-agent copy (TYPE=Nodes, SYSTEM_CODE IS NULL);
	 * null when $templateId is not such a copy or carries no owner. Used to re-launch a
	 * freshly upgraded copy from its owner instead of the administrator who triggered the
	 * upgrade, so the onboarding start branch is attributed to the owner.
	 */
	public function getActivatedBy(int $templateId): ?int
	{
		if ($templateId <= 0)
		{
			return null;
		}

		$value = WorkflowTemplateTable::query()
			->setSelect(['ACTIVATED_BY'])
			->where('ID', $templateId)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('SYSTEM_CODE')
			->setLimit(1)
			->fetch()['ACTIVATED_BY'] ?? null
		;

		$value = (int)$value;

		return $value > 0 ? $value : null;
	}

	/**
	 * AI-agent template that may be used as a copyAndStart source: a Nodes template
	 * from the AI_AGENT section that is not a launched copy (ACTIVATED_AT IS NULL).
	 * Covers both a system AI-agent template (SYSTEM_CODE set) and a user-created one
	 * (SYSTEM_CODE IS NULL) added via the designer, and rejects launched copies, so the
	 * settings of an agent already activated by another user cannot be cloned by id.
	 *
	 * @return array{SYSTEM_CODE: ?string}|null null when $templateId is not a valid copy
	 *  source: a launched copy, a template of another type/section or a missing template.
	 */
	public function findCopyableAiAgentTemplate(int $templateId): ?array
	{
		if ($templateId <= 0)
		{
			return null;
		}

		$row = WorkflowTemplateSectionTable::query()
			->where('TEMPLATE_ID', $templateId)
			->where('SECTION_ID', WorkflowTemplateSection::AiAgent->value)
			->where('TEMPLATE.TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('TEMPLATE.ACTIVATED_AT')
			->setSelect(['ID', 'SYSTEM_CODE' => 'TEMPLATE.SYSTEM_CODE'])
			->setLimit(1)
			->fetch()
		;

		if (empty($row))
		{
			return null;
		}

		return ['SYSTEM_CODE' => $row['SYSTEM_CODE'] ?? null];
	}

	/**
	 * Whether the system template $systemCode already has at least one active launched copy in the
	 * AI_AGENT section. Existence only: the caller needs a yes/no answer, never a count, an owner
	 * or a name.
	 *
	 * The link and the definition of an active copy both come from LaunchedCopyQuery, so this
	 * predicate cannot drift away from the grid filter that the user opens right after.
	 *
	 * Who launched the copy is deliberately not part of the predicate: any active copy visible in
	 * the grid counts, including one created by a trusted scenario. Hidden system codes are not
	 * excluded here either - hiding works by SYSTEM_CODE, and a launched copy always has none, so
	 * it cannot change the set of copies; it is a property of the source template instead
	 * (LaunchableTemplateResolver).
	 */
	public function hasActiveLaunchedCopy(string $systemCode): bool
	{
		if ($systemCode === '')
		{
			return false;
		}

		$query = WorkflowTemplateTable::query()
			->setSelect(['ID'])
			->registerRuntimeField(
				'SECTION',
				new Reference(
					'SECTION',
					WorkflowTemplateSectionTable::class,
					Join::on('this.ID', 'ref.TEMPLATE_ID'),
				),
			)
			->where('SECTION.SECTION_ID', WorkflowTemplateSection::AiAgent->value)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->setLimit(1)
		;

		$query->where(LaunchedCopyQuery::joinOriginLink($query, [$systemCode]));
		LaunchedCopyQuery::applyActive($query);

		return !empty($query->fetch());
	}

	/**
	 * SYSTEM_CODE of a system AI-agent template (TYPE=Nodes, SYSTEM_CODE set),
	 * or null when it is not a system AI-agent template: a launched copy
	 * (SYSTEM_CODE IS NULL), a system template of another type (e.g.
	 * bitrix_bizproc_automation with TYPE robots) or a missing template.
	 * Mirror of canManageLaunchedTemplate on the SYSTEM_CODE side.
	 */
	public function getSystemAiAgentCode(int $templateId): ?string
	{
		if ($templateId <= 0)
		{
			return null;
		}

		return WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNotNull('SYSTEM_CODE')
			->setSelect(['SYSTEM_CODE'])
			->setLimit(1)
			->fetch()['SYSTEM_CODE'] ?? null
		;
	}

	public function saveOriginSystemCode(int $templateId, string $systemCode): void
	{
		WorkflowTemplateSettingsTable::add([
			'TEMPLATE_ID' => $templateId,
			'NAME' => WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_CODE,
			'VALUE' => $systemCode,
		]);
	}

	public function getOriginSystemCode(int $templateId): ?string
	{
		if ($templateId <= 0)
		{
			return null;
		}

		$value = WorkflowTemplateSettingsTable::query()
			->setSelect(['VALUE'])
			->where('TEMPLATE_ID', $templateId)
			->where('NAME', WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_CODE)
			->setLimit(1)
			->fetch()['VALUE'] ?? null
		;

		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * Installed reference version of a launched copy: the revision of the
	 * reference logic at the moment the copy was created or last upgraded.
	 * Stored alongside ORIGIN_SYSTEM_CODE as a single-valued setting.
	 */
	public function saveOriginSystemVersion(int $templateId, string $version): void
	{
		WorkflowTemplateSettingsTable::deleteSettingsByFilter([
			'=TEMPLATE_ID' => $templateId,
			'=NAME' => WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_VERSION,
		]);

		WorkflowTemplateSettingsTable::add([
			'TEMPLATE_ID' => $templateId,
			'NAME' => WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_VERSION,
			'VALUE' => $version,
		]);
	}

	public function getOriginSystemVersion(int $templateId): ?string
	{
		if ($templateId <= 0)
		{
			return null;
		}

		$value = WorkflowTemplateSettingsTable::query()
			->setSelect(['VALUE'])
			->where('TEMPLATE_ID', $templateId)
			->where('NAME', WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_VERSION)
			->setLimit(1)
			->fetch()['VALUE'] ?? null
		;

		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * Batch-reads ORIGIN_SYSTEM_CODE and ORIGIN_SYSTEM_VERSION for many templates in a single
	 * query, so the grid hot path does not issue the per-row lookups that getOriginSystemCode()
	 * and getOriginSystemVersion() would otherwise perform for every launched copy (NFR P95).
	 * Empty values are normalized to null, mirroring the single-row getters.
	 *
	 * @param list<int> $templateIds
	 * @return array<int, array{code: ?string, version: ?string}> Keyed by TEMPLATE_ID; only
	 *   templates that carry at least one of the two settings are present.
	 */
	public function getOriginSettings(array $templateIds): array
	{
		$templateIds = array_values(array_unique(array_filter(
			array_map(static fn($id) => (int)$id, $templateIds),
		)));
		if (empty($templateIds))
		{
			return [];
		}

		$rows = WorkflowTemplateSettingsTable::query()
			->setSelect(['TEMPLATE_ID', 'NAME', 'VALUE'])
			->whereIn('TEMPLATE_ID', $templateIds)
			->whereIn('NAME', [
				WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_CODE,
				WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_VERSION,
			])
			->fetchAll()
		;

		$settings = [];
		foreach ($rows as $row)
		{
			$templateId = (int)$row['TEMPLATE_ID'];
			$value = is_string($row['VALUE']) && $row['VALUE'] !== '' ? $row['VALUE'] : null;

			if ($row['NAME'] === WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_CODE)
			{
				$settings[$templateId]['code'] = $value;
			}
			elseif ($row['NAME'] === WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_VERSION)
			{
				$settings[$templateId]['version'] = $value;
			}
		}

		return $settings;
	}

	public function updateActivationTimestamp(int $templateId, DateTime $dateTime): UpdateResult
	{
		$fieldsToUpdate = [
			'ACTIVATED_AT' => $dateTime,
		];

		$result = WorkflowTemplateTable::update($templateId, $fieldsToUpdate);

		// ACTIVATED_AT is part of the started launched copy condition the warm state holds, so this write
		// invalidates it exactly as a change of the owner does.
		$this->forgetWarmedTransferRights($templateId);

		return $result;
	}

	/**
	 * Owner of a started launched copy, read under a row lock held until the transaction of the caller
	 * ends; null when $templateId is no longer such a copy or carries no owner.
	 *
	 * The right to transfer an agent is the right of whoever owns it right now, so it cannot be answered
	 * against a state the write does not see: two transfers of the same copy sent at once would both be
	 * authorized while the first of them has not moved the owner yet, and the second would hand the agent
	 * back to an owner who has already lost it. Locking the row before the right is asked serializes the
	 * two: the second transfer waits here and reads the owner the first one left.
	 *
	 * The warm state of the predicate is dropped along with the read, because an answer prepared for the
	 * page before this lock describes the owner as it was, and the check that follows must not use it.
	 */
	public function lockStartedLaunchedCopyOwner(int $templateId): ?int
	{
		if ($templateId <= 0)
		{
			return null;
		}

		$connection = Application::getConnection();

		$row = $connection->query((new SqlExpression(
			'SELECT ?# FROM ?#'
			. ' WHERE ?# = ?i AND ?# = ?s AND ?# IS NULL AND ?# IS NOT NULL'
			. ' FOR UPDATE',
			'ACTIVATED_BY',
			WorkflowTemplateTable::getTableName(),
			'ID',
			$templateId,
			'TYPE',
			WorkflowTemplateType::Nodes->value,
			'SYSTEM_CODE',
			'ACTIVATED_AT',
		))->compile())->fetch();

		$this->forgetWarmedTransferRights($templateId);

		if (!is_array($row))
		{
			return null;
		}

		$owner = (int)($row['ACTIVATED_BY'] ?? 0);

		return $owner > 0 ? $owner : null;
	}

	/**
	 * Hands a launched copy over to $newOwnerId while its owner is still $expectedOwnerId.
	 *
	 * The condition is the actuality check of the whole transfer, which is why it is a condition of the
	 * write itself and not a read before it: a copy whose owner changed meanwhile, or which stopped
	 * being a started launched copy, keeps everything it has and is reported as not updated.
	 *
	 * ACTIVATED_BY is the only field written. Authorship (CREATED_BY), the user of the copy (USER_ID),
	 * ACTIVATED_AT, SYSTEM_CODE, CREATE_SOURCE and the logic of the template stay as they are, so a
	 * transfer changes who owns the agent and nothing about what the agent is.
	 *
	 * @return bool false when no row matched the condition and nothing was written
	 */
	public function changeOwnerIfCurrent(int $templateId, int $expectedOwnerId, int $newOwnerId): bool
	{
		if ($templateId <= 0 || $expectedOwnerId <= 0 || $newOwnerId <= 0 || $expectedOwnerId === $newOwnerId)
		{
			return false;
		}

		$connection = Application::getConnection();

		$connection->queryExecute((new SqlExpression(
			'UPDATE ?# SET ?# = ?i'
			. ' WHERE ?# = ?i AND ?# = ?i AND ?# = ?s AND ?# IS NULL AND ?# IS NOT NULL',
			WorkflowTemplateTable::getTableName(),
			'ACTIVATED_BY',
			$newOwnerId,
			'ID',
			$templateId,
			'ACTIVATED_BY',
			$expectedOwnerId,
			'TYPE',
			WorkflowTemplateType::Nodes->value,
			'SYSTEM_CODE',
			'ACTIVATED_AT',
		))->compile());

		// Warm state of the transfer predicate describes who owns the copy, and this is the write that
		// moves it: whatever was read for the page before is no longer an answer about this id.
		$this->forgetWarmedTransferRights($templateId);

		// The new owner always differs from the expected one, so a matched row is always a changed row
		// and the count MySQL reports for changed rows only stays a faithful answer.
		return $connection->getAffectedRowsCount() > 0;
	}

	/**
	 * Drops the warm state of one id, so the next check reads the row again. Called by every writer of the
	 * fields the state holds (ACTIVATED_BY, ACTIVATED_AT) and called whether the write matched a row or not:
	 * dropping a still valid answer costs one query, keeping a stale one costs a wrong answer.
	 */
	private function forgetWarmedTransferRights(int $templateId): void
	{
		unset($this->startedLaunchedCopies[$templateId], $this->startedLaunchedCopyOwners[$templateId]);
	}
}
