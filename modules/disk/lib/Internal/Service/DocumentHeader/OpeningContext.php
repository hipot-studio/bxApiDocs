<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DocumentHeader;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;

/**
 * A concrete opening of an object, described the way the header of that opening asks about it (ALG-01).
 *
 * Every answer is decided when the context is built {@see OpeningContextFactory}, so the composition
 * matrix reaches no storage. The transition to editing is the exception and may arrive undecided: it
 * costs the entity the document is attached to, and most openings never ask it {@see canEdit()}.
 */
final class OpeningContext
{
	public const ACCESS_TYPE_INTERNAL = 'internal';
	public const ACCESS_TYPE_UNIFIED = 'unified';
	public const ACCESS_TYPE_EXTERNAL = 'external';

	private ?bool $resolvedCanEdit = null;

	/**
	 * @param string $accessType one of self::ACCESS_TYPE_*
	 * @param bool|\Closure(): bool $canEdit an answer, or the way to reach it on the first question
	 */
	public function __construct(
		private readonly ?File $file,
		private readonly string $accessType,
		private readonly bool $isViewMode,
		private readonly bool $isBoard,
		private readonly bool $canRead,
		private readonly bool|\Closure $canEdit,
		private readonly bool $canRename,
		private readonly bool $canShare,
		private readonly bool $canChangeRights,
		private readonly bool $offersObjectSharing = true,
		private readonly bool $servesContentItself = false,
		private readonly ?int $versionId = null,
		private readonly ?DocumentService $editorService = null,
		private readonly ?AttachedObject $attachedObject = null,
		private readonly ?ExternalLink $externalLink = null,
		private readonly ?string $name = null,
		private readonly string $downloadUrl = '',
	)
	{
	}

	/** Null when the opening names no object any more: an orphaned revision is still served as a page. */
	public function file(): ?File
	{
		return $this->file;
	}

	/** A revision keeps the name it was saved under, so renaming the object does not retitle the page. */
	public function name(): string
	{
		return $this->name ?? (string)$this->file?->getName();
	}

	/** The extension of what is served, which for a revision is not the one of the object. */
	public function contentExtension(): string
	{
		return (string)getFileExtension($this->name());
	}

	/** Empty when the opening offers no downloading. */
	public function downloadUrl(): string
	{
		return $this->downloadUrl;
	}

	public function versionId(): ?int
	{
		return $this->versionId;
	}

	public function attachedObject(): ?AttachedObject
	{
		return $this->attachedObject;
	}

	public function externalLink(): ?ExternalLink
	{
		return $this->externalLink;
	}

	public function accessType(): string
	{
		return $this->accessType;
	}

	public function isViewMode(): bool
	{
		return $this->isViewMode;
	}

	public function isBoard(): bool
	{
		return $this->isBoard;
	}

	public function editorService(): ?DocumentService
	{
		return $this->editorService;
	}

	public function canRead(): bool
	{
		return $this->canRead;
	}

	/** Reached at most once: a board, a revision and an editing opening decide their row without it. */
	public function canEdit(): bool
	{
		return $this->resolvedCanEdit ??= is_bool($this->canEdit)
			? $this->canEdit
			: ($this->canEdit)()
		;
	}

	/** The right on the object itself, which the rename of the header asks for (API-03). */
	public function canRename(): bool
	{
		return $this->canRename;
	}

	public function canShare(): bool
	{
		return $this->canShare;
	}

	public function canChangeRights(): bool
	{
		return $this->canChangeRights;
	}

	/**
	 * Whether the surface offers the object itself - its access popup, its link and its identifiers. A
	 * revision has no access of its own and an attachment is configured where it is attached, while the
	 * shells of the editors offer both, so the answer belongs to the surface and not to the object.
	 */
	public function offersObjectSharing(): bool
	{
		return $this->offersObjectSharing;
	}

	/**
	 * Whether the surface draws the content itself, as the viewer does and the shells of the editors do
	 * not. Asked of the surface and not of the engine: a session naming an engine the module no longer
	 * knows is still drawn by the shell of an editor.
	 */
	public function servesContentItself(): bool
	{
		return $this->servesContentItself;
	}
}
