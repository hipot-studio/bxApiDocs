<?php

namespace Bitrix\Crm\Import\ImportEntityFields\Trait;

trait CanConfigureReadonlyTrait
{
	protected bool $isReadonly = false;

	public function configureReadonly(bool $isReadonly): self
	{
		$this->isReadonly = $isReadonly;

		return $this;
	}

	public function isReadonly(): bool
	{
		return $this->isReadonly ?? false;
	}
}
