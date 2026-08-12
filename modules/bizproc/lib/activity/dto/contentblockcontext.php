<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

/**
 * Resolution context passed to \Bitrix\Bizproc\Public\Activity\Interface\ActivityContentBlockProviderInterface::getContentBlock().
 *
 * Carries the {@see ContentBlockScope} assembled from the whole template so a consumer activity can
 * resolve cross-node references for display (e.g. a dynamic storage title declared by a separate
 * CreateStorageNode) by key, without knowing the producer's node type. Display-only; never persisted.
 */
final class ContentBlockContext
{
	public function __construct(
		public readonly ?ContentBlockScope $scope = null,
	) {}
}
