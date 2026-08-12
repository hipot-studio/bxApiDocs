<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Interface;

/**
 * Implemented by activities that declare labels other activities may reference in their content
 * block (e.g. a CreateStorageNode declares a dynamic storage title that storage read/write/delete
 * nodes show). Producers declare; consumers resolve from the scope. Display-only.
 */
interface ContentBlockScopeProducerInterface
{
	/**
	 * Declarative description of the label this producer contributes to the shared scope.
	 * Both the server (ContentBlockResolver::buildScope) and the editor client build the scope
	 * from this single declaration — no imperative per-class logic, no PHP↔JS drift.
	 *
	 * @return array{namespace: string, keyProperty: string, labelProperty: string}
	 *   namespace     — scope namespace (e.g. "storage")
	 *   keyProperty   — Properties key holding the scope key (e.g. "StorageCode")
	 *   labelProperty — Properties key holding the label (e.g. "StorageTitle")
	 */
	public static function getScopeContribution(): array;
}
