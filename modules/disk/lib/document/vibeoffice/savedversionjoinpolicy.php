<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Closure;
use Throwable;

/** @internal */
final class SavedVersionJoinPolicy
{
	private readonly SavedEditSessionSelector $sessions;
	private readonly Closure $auditActive;

	public function __construct(?SavedEditSessionSelector $sessions = null, ?Closure $auditActive = null)
	{
		$this->sessions = $sessions ?? new SavedEditSessionSelector();
		$this->auditActive = $auditActive
			?? static fn(): bool => class_exists(AuditTransaction::class) && AuditTransaction::isActive();
	}

	/** The caller holds the document and object locks inside its content transaction. */
	public function isAllowed(string $hostDocKey, int $realObjectId): bool
	{
		try
		{
			if ($hostDocKey === '' || $realObjectId <= 0 || !($this->auditActive)())
			{
				return false;
			}
			$matched = false;
			foreach ($this->sessions->iterate($realObjectId) as $session)
			{
				if (HostDocumentKey::resolve($session) !== $hostDocKey)
				{
					continue;
				}
				$context = $session->getContext();
				$metadata = DocumentSessionManager::extractVoMetadata($session);
				$baseline = $metadata[DocumentSessionManager::CONTEXT_VO_CONTENT_VERSION_KEY] ?? null;
				if (!$context || $context->getObjectId() !== $realObjectId
					|| (int)$context->getExternalLinkId() !== (int)$session->getExternalLinkId()
					|| !is_int($baseline) || $baseline < 0)
				{
					return false;
				}
				$matched = true;
			}

			return $matched;
		}
		catch (Throwable)
		{
			return false;
		}
	}
}
