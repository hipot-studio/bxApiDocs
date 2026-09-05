<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\ExternalLink;
use Bitrix\Main\Engine\CurrentUser;

/**
 * Maps the result of the Disk rights check (read/edit/full, with the double File +
 * AttachedObject check) into a vibeoffice grant `mode` (P4.T3, contract GRANT-MAP).
 *
 * The wire-values are exactly the platform mode strings: `view`/`comment`/`review`/
 * `fill_forms`/`edit` (api-reference §3.1; note `fill_forms`, NOT `fillForms` — see the
 * Errata in the main SDD). Used both at `open` (the `grant.mode` of the open-body) and
 * for the batch `protocolClient->upsertGrants()`.
 *
 * Base mapping (canonical GRANT-MAP block):
 *  - no edit right, has read    → `view`
 *  - edit / full (`canUpdate`)  → `edit`
 *
 * The intermediate roles (`comment`/`review`/`fill_forms`) are kept as reserve only and
 * are NOT used by default — Disk does not distinguish them in its current rights model.
 * The double File + AttachedObject check stays the source of truth for the role; the
 * security model is never weakened here (ADR §3 invariant).
 */
final class GrantMapper
{
	public const MODE_VIEW = 'view';
	public const MODE_COMMENT = 'comment';
	public const MODE_REVIEW = 'review';
	public const MODE_FILL_FORMS = 'fill_forms';
	public const MODE_EDIT = 'edit';

	/**
	 * Resolves the grant `mode` for the current user on a given session.
	 *
	 * Mirrors the controller's double rights check: an edit session grants `edit` only
	 * when the user actually retains edit rights (`canUserEdit`), otherwise it degrades to
	 * `view`. A view session always maps to `view`.
	 */
	public function resolveForSession(DocumentSession $documentSession, CurrentUser $user): string
	{
		$canEdit = $documentSession->isEdit() && $documentSession->canUserEdit($user);

		return $this->mapFromRights($canEdit);
	}

	/**
	 * Resolves the grant `mode` for an open reached through a public/external link (P8.T2,
	 * ALG-EXTERNAL-GRANT). The source of truth is the {@see ExternalLink} — NOT the rights of any
	 * `CurrentUser` (an anonymous visitor has none): an edit session grants `edit` only when the
	 * link itself allows editing ({@see ExternalLink::allowEdit()}), otherwise it degrades to
	 * `view`. A view session always maps to `view`. The access is therefore never wider than the
	 * `ExternalLink` (ADR §3/§11 invariant of group C).
	 */
	public function resolveForExternalLink(DocumentSession $documentSession, ExternalLink $link): string
	{
		$canEdit = $documentSession->isEdit() && $link->allowEdit();

		return $this->mapFromRights($canEdit);
	}

	/**
	 * Maps a resolved edit/read decision into the grant `mode`.
	 *
	 * @param bool $canEdit true when the user has edit/full rights (GRANT-MAP row 2);
	 *                      false maps to `view` (GRANT-MAP row 1: read-only / no edit).
	 */
	public function mapFromRights(bool $canEdit): string
	{
		return $canEdit ? self::MODE_EDIT : self::MODE_VIEW;
	}
}
