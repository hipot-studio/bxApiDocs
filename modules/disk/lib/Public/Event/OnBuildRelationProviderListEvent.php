<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Event;

/**
 * Asks every module that owns entities to name its provider of relations.
 *
 * A handler answers with ['MODULE_ID' => string, 'CLASS' => class-string<RelationProviderInterface>],
 * or with a list of such records; the module id of the record has to be the one the provider itself
 * answers with. A module that does not answer has no provider and no published entity types - the
 * contour then serves its pages without relations of that module rather than failing.
 */
class OnBuildRelationProviderListEvent extends AbstractEvent
{
	public const EVENT_NAME = 'onBuildRelationProviderList';

	public function __construct()
	{
		parent::__construct(event: static::EVENT_NAME);
	}
}
