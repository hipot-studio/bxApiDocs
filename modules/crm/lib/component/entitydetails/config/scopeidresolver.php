<?php

namespace Bitrix\Crm\Component\EntityDetails\Config;

use Bitrix\Crm\Entity\EntityEditorConfig;
use Bitrix\Crm\Entity\EntityEditorConfigScope;
use Bitrix\Crm\Service\Container;

final class ScopeIdResolver
{
	private int $userId;

	public function __construct(
		private readonly int $entityTypeId,
		private readonly ?int $categoryId = null
	)
	{
		$this->userId = Container::getInstance()->getContext()->getUserId();
	}

	public function setUserId(int $userId): self
	{
		$this->userId = $userId;

		return $this;
	}

	public function getScopeId(string $prefix, string $personalSuffix = null): string
	{
		$configScope = 0;
		$userScopeId = null;
		$categoryId = (int)$this->categoryId;
		try
		{
			$entityEditorConfig = EntityEditorConfig::createWithCurrentScope($this->entityTypeId, [
				'USER_ID' => $this->userId,
				'CATEGORY_ID' => $categoryId,
			]);
			$configScope = $entityEditorConfig->getScope();
			$userScopeId = $entityEditorConfig->getUserScopeId();
		}
		finally
		{
			$entityType = \CCrmOwnerType::ResolveName($this->entityTypeId);
			if (!$configScope || ($configScope === EntityEditorConfigScope::PERSONAL && $personalSuffix !== null))
			{
				return mb_strtolower($entityType . ($categoryId > 0 ? '_' . $categoryId : '') . $personalSuffix);
			}

			$optionNameParts = [
				$prefix,
				$configScope,
				$entityType,
			];

			if ($categoryId)
			{
				$optionNameParts[] = $categoryId;
			}

			if ($userScopeId)
			{
				$optionNameParts[] = $userScopeId;
			}

			return mb_strtolower(implode('_', $optionNameParts));
		}
	}
}
