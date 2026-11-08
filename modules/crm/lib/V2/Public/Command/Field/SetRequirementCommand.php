<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Field;

use Bitrix\Crm\V2\Internal\Service\Field\Requirement\Service;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\Exception;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\Requirement;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\SetInput;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Field\RequirementProvider;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\Result;

/**
 * Replaces the additional requirement rule of one CRM field on behalf of one user.
 */
final class SetRequirementCommand implements CommandInterface
{
	private \Closure $operation;

	public function __construct(
		private readonly EntityType $entityType,
		private readonly int $userId,
		private readonly string $fieldId,
		private readonly ?int $categoryId,
		private readonly SetInput $input,
	)
	{
		$this->operation = static fn (
			EntityType $entityType,
			int $userId,
			string $fieldId,
			?int $categoryId,
			SetInput $input,
		): Requirement => (new Service())->set(
			$entityType,
			$userId,
			$fieldId,
			$categoryId,
			$input,
		);
	}

	public function run(): Result
	{
		try
		{
			$requirement = ($this->operation)(
				$this->entityType,
				$this->userId,
				$this->fieldId,
				$this->categoryId,
				$this->input,
			);

			return (new Result())->setData([
				RequirementProvider::DATA_REQUIREMENT => $requirement,
			]);
		}
		catch (Exception $exception)
		{
			return (new Result())->addError($exception->getError()->toError());
		}
	}
}
