<?php

namespace Bitrix\Crm\Integration\AI\Function\Category;

use Bitrix\Crm\Category\Entity\Category;
use Bitrix\Crm\Integration\AI\Contract\AIFunction;
use Bitrix\Crm\Integration\AI\Function\Category\Dto\RenameParameters;
use Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\Funnel\RenameEvent;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\UserPermissions;

final class Rename implements AIFunction
{
	private readonly UserPermissions\EntityPermissions\Category $permissions;

	public function __construct(
		private readonly int $currentUserId,
	)
	{
		$this->permissions = Container::getInstance()->getUserPermissions($this->currentUserId)->category();
	}

	public function isAvailable(): bool
	{
		return true;
	}

	public function invoke(...$args): \Bitrix\Main\Result
	{
		$parameters = new RenameParameters($args);
		if ($parameters->hasValidationErrors())
		{
			return Result::fail($parameters->getValidationErrors());
		}

		/** @var Factory $factory */
		$factory = Container::getInstance()->getFactory($parameters->entityTypeId);

		/** @var Category $category */
		$category = $factory->getCategory($parameters->categoryId);

		if (!$this->permissions->canUpdate($category))
		{
			return Result::failAccessDenied();
		}

		$result = $category
			->setName($parameters->title)
			->save()
		;

		(new RenameEvent(section: Dictionary::SECTION_AI))
			->setStatus($result->isSuccess() ? Dictionary::STATUS_SUCCESS : Dictionary::STATUS_ERROR)
			->buildEvent()
			->send()
		;

		return $result;
	}
}
