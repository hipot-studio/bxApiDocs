<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Field;

use Bitrix\Crm\V2\Internal\Service\Field\Requirement\Service;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\Exception;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\Requirement;
use Bitrix\Crm\V2\Public\EntityType;

/**
 * Reads field requirement settings of one entity type on behalf of one user.
 *
 * The read is uncached and does not create categories, stages, or field settings.
 */
final class RequirementProvider
{
	/** Result data key containing a field requirement. */
	public const DATA_REQUIREMENT = 'requirement';

	private readonly Service $service;

	private function __construct(
		private readonly EntityType $entityType,
		private readonly int $userId,
	)
	{
		$this->service = new Service();
	}

	public static function forEntityType(EntityType $entityType, int $userId): self
	{
		return new self($entityType, $userId);
	}

	/**
	 * @throws Exception
	 * @throws \RuntimeException
	 */
	public function get(string $fieldId, ?int $categoryId = null): Requirement
	{
		return $this->service->read($this->entityType, $this->userId, $fieldId, $categoryId);
	}
}
