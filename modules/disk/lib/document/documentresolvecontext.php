<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document;

/**
 * Immutable context carried into {@see DocumentHandlersManager::resolveEffectiveHandler()}.
 *
 * Generalises the previous `(objectId, attachedObjectId)` pair (Q-5) so the single engine-selection
 * seam can also tell an owner-initiated open (`forObject()`) from an open reached through a public
 * external link (`forExternalLink()`, `isExternalAccess() === true`). The extra `externalLinkId` /
 * `isExternalAccess` carry the external-link identity important for future public-link narrowing.
 *
 * The resolver uses the context to narrow the engine by project: when a workgroup allowlist is
 * configured ({@see Vibeoffice\Configuration::getEnabledGroupIds()}) an office open is translated to
 * vibeoffice only if its `objectId` resolves to a file in the disk of an allowlisted workgroup
 * ({@see \Bitrix\Disk\ProxyType\Group} storage); with an empty allowlist the context does not affect
 * the result and the feature stays portal-wide. `isExternalAccess` is intentionally not consulted —
 * the engine is a property of the file's storage, identical on every access path — see
 * {@see DocumentHandlersManager::resolveEffectiveHandler()}.
 */
final class DocumentResolveContext
{
	private function __construct(
		private readonly ?int $objectId,
		private readonly ?int $attachedObjectId,
		private readonly ?int $externalLinkId,
		private readonly bool $isExternalAccess,
	)
	{
	}

	/**
	 * Context of an owner/authorized open by file/attached object (not an external-link access).
	 */
	public static function forObject(?int $objectId, ?int $attachedObjectId): self
	{
		return new self($objectId, $attachedObjectId, null, false);
	}

	/**
	 * Context of an open reached through a public/external link ({@see \Bitrix\Disk\ExternalLink}).
	 */
	public static function forExternalLink(?int $externalLinkId, ?int $objectId): self
	{
		return new self($objectId, null, $externalLinkId, true);
	}

	public function getObjectId(): ?int
	{
		return $this->objectId;
	}

	public function getAttachedObjectId(): ?int
	{
		return $this->attachedObjectId;
	}

	public function getExternalLinkId(): ?int
	{
		return $this->externalLinkId;
	}

	public function isExternalAccess(): bool
	{
		return $this->isExternalAccess;
	}
}
