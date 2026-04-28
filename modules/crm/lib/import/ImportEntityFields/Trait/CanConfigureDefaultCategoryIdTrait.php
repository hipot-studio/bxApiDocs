<?php

namespace Bitrix\Crm\Import\ImportEntityFields\Trait;

trait CanConfigureDefaultCategoryIdTrait
{
	private ?int $defaultCategoryId = null;

	public function configureDefaultCategoryId(?int $defaultCategoryId): self
	{
		$this->defaultCategoryId = $defaultCategoryId;

		return $this;
	}
}
