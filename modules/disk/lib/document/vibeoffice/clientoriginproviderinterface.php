<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

/**
 * Source of the origin of the portal page the editor is opened on: a canonical HTTPS origin,
 * or null when the open must not declare one.
 */
interface ClientOriginProviderInterface
{
	public function getOrigin(): ?string;
}
