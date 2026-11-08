<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Fragment;

use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\BizprocDesigner\Internal\Service\TemplateEditAccess;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;

/**
 * Who may see and manage a saved fragment. Every rule is built out of the primitives the templates
 * already have: the library is open to whoever may reach the templates, a fragment is cut from a template
 * by whoever may edit that template, and it is changed and removed by its author or - once the author is
 * gone from the portal - by the administrator of the domain: the right to configure the matrix of the
 * rights, the only primitive that means both the administrator of the portal and the administrator of the
 * workflows.
 *
 * The rules live here and not inside the service because the list asks them as a condition of its select
 * while an operation by id asks them of a row: two copies of the same rule would let a fragment show up in
 * the list while the operation on it is refused, or the other way round.
 *
 * The personal fragment of an active author is fail-closed: it reaches nobody but the author, the
 * administrator included, neither through the list nor through an operation by id, because a refusal that
 * told a foreign personal fragment from a missing one would disclose that it exists.
 */
final class FragmentAccess
{
	private TemplateAccessService $templateAccessService;
	private ?TemplateEditAccess $templateEditAccess;

	/** @var array<int, bool> user id => whether they configure the matrix of the rights */
	private array $domainAdmins = [];

	public function __construct(
		?TemplateAccessService $templateAccessService = null,
		?TemplateEditAccess $templateEditAccess = null,
	)
	{
		$this->templateAccessService = $templateAccessService ?? new TemplateAccessService();
		$this->templateEditAccess = $templateEditAccess;
	}

	/**
	 * The fields the rules read of a row, joined together with it: whether the author is still active is a
	 * condition of the visibility, and asking it per row of the answer would cost a query per row.
	 *
	 * @return array<string, string> alias => the field of the joined author
	 */
	public static function selectOfAuthorActivity(): array
	{
		return [
			'AUTHOR_ROW_ID' => 'AUTHOR.ID',
			'AUTHOR_ACTIVE' => 'AUTHOR.ACTIVE',
			'AUTHOR_IS_REAL_USER' => 'AUTHOR.IS_REAL_USER',
		];
	}

	public function canUseLibrary(int $userId): bool
	{
		return $userId > 0 && $this->templateAccessService->canAccessTemplates($userId);
	}

	/**
	 * A fragment is cut from a template by whoever may edit that very template. The fork "a Nodes template
	 * against a legacy one" is not repeated here: it is an access boundary of the editor, and its copies
	 * drift apart silently ({@see TemplateEditAccess}).
	 *
	 * A scheme that has never been saved has no row to ask about, and there the right to cut a fragment out of
	 * it is the right to author a template of that document type - the same branch the preparation of an
	 * insertion takes for the same case (API-05). Answering by the rights instead would tell somebody working
	 * on their own unsaved scheme that they have no access to it, and the item of the menu that leads here is
	 * not gated by the client at all. The type is the one of the request on this branch only, and it is what
	 * the right is asked in: there is no row to read a type off.
	 *
	 * @param array $documentType the document type of the source, read for a template that is not saved yet
	 */
	public function canCreateFrom(int $templateId, int $userId, array $documentType = []): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		if ($templateId <= 0)
		{
			return $documentType !== [] && $this->templateAccessService->canCreate($userId, $documentType);
		}

		$editAccess = $this->templateEditAccess();
		if ($editAccess === null)
		{
			return false;
		}

		$tpl = $editAccess->resolve($templateId);

		return $tpl !== null && $editAccess->canEdit($tpl, $userId);
	}

	public function isDomainAdmin(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return $this->domainAdmins[$userId] ??= $this->templateAccessService->canConfigureRights($userId);
	}

	/**
	 * The visibility as a condition of the select (ALG-03, rule 1). A missing row of the author - the
	 * account was deleted rather than deactivated - reads as an inactive author, hence the branch of its
	 * own: a condition on NULL is false by itself, and such a fragment would drop out of every answer and
	 * become impossible to remove.
	 */
	public function visibilityCondition(int $userId): ConditionTree
	{
		return Query::filter()
			->logic('or')
			->where('IS_SHARED', 'Y')
			->where('AUTHOR_ID', $userId)
			->whereNull('AUTHOR.ID')
			->whereNot(
				Query::filter()
					->where('AUTHOR.ACTIVE', 'Y')
					->where('AUTHOR.IS_REAL_USER', 'Y')
			)
		;
	}

	private function isVisible(array $row, int $userId): bool
	{
		return $this->isShared($row) || $this->isAuthor($row, $userId) || !$this->isAuthorActive($row);
	}

	/**
	 * The name and the visibility of a fragment are changed by its author, and by the administrator of the
	 * domain once the author is gone (ALG-03, rule 2). While the author is there the rule does not act at
	 * all: their personal fragment is out of reach of the administrator too, and their shared one is not
	 * the administrator's to rename.
	 */
	public function canEdit(array $row, int $userId): bool
	{
		return $this->isAuthor($row, $userId)
			|| (!$this->isAuthorActive($row) && $this->isDomainAdmin($userId))
		;
	}

	/**
	 * Whether this write takes the fragment over (ALG-03, rule 4). The predicate names no administrator of
	 * its own: only {@see self::canEdit()} lets a write through, so somebody writing a fragment that is not
	 * theirs is the administrator by the very fact of being here.
	 *
	 * The claim is one half of a rule whose other half is a read, and only the read is symmetric: an
	 * employee back in service sees their personal fragment again as long as nobody has stepped in, and
	 * never once somebody has (ADR, section 3).
	 */
	public function claimsOnWrite(array $row, int $userId): bool
	{
		return $userId > 0 && !$this->isAuthor($row, $userId) && !$this->isAuthorActive($row);
	}

	/**
	 * The author removes their own fragment, and the administrator of the domain removes what they can see
	 * (ALG-03, rule 3): the personal fragment of an active author is not theirs to remove either.
	 */
	public function canDelete(array $row, int $userId): bool
	{
		return $this->isAuthor($row, $userId)
			|| ($this->isDomainAdmin($userId) && $this->isVisible($row, $userId))
		;
	}

	/**
	 * The predicate of the activity of the author (ALG-03): an employee of the portal who is still there.
	 * The membership of the intranet is deliberately not part of it - it cannot be expressed in the select
	 * the list runs, and the editor is closed to everybody outside the intranet anyway.
	 */
	private function isAuthorActive(array $row): bool
	{
		return (int)($row['AUTHOR_ROW_ID'] ?? 0) > 0
			&& ($row['AUTHOR_ACTIVE'] ?? '') === 'Y'
			&& ($row['AUTHOR_IS_REAL_USER'] ?? '') === 'Y'
		;
	}

	/**
	 * The visibility a client is told about: the fragment of an inactive author is open to everyone
	 * whatever its own switch says, and the switch itself is never rewritten by a read (DTO-01).
	 */
	public function isEffectivelyShared(array $row): bool
	{
		return $this->isShared($row) || !$this->isAuthorActive($row);
	}

	private function isShared(array $row): bool
	{
		return ($row['IS_SHARED'] ?? '') === 'Y';
	}

	private function isAuthor(array $row, int $userId): bool
	{
		return $userId > 0 && (int)($row['AUTHOR_ID'] ?? 0) === $userId;
	}

	private function templateEditAccess(): ?TemplateEditAccess
	{
		if ($this->templateEditAccess === null && Loader::includeModule('bizprocdesigner'))
		{
			$this->templateEditAccess = new TemplateEditAccess();
		}

		return $this->templateEditAccess;
	}
}
