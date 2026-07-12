<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Adapter;

use Bitrix\Main\Access;
use Bitrix\Socialnetwork\V2\Internal\Entity;

interface EntityModelAdapterInterface
{
	public function __construct(Entity\EntityInterface $entity);

	/**
	 * Transform an entity to a model using entity data
	 */
	public function transform(): ?Access\AccessibleItem;

	/**
	 * Create a new model based on the entity ID
	 */
	public function create(): ?Access\AccessibleItem;
}
