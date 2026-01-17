<?php

namespace Bitrix\Crm\Tour\Mixin;

trait HasEntitySupport
{
	protected ?int $entityTypeId = null;
	protected ?int $entityId = null;

	final public function setEntityTypeId(?int $entityTypeId): self
	{
		$this->entityTypeId = $entityTypeId;

		return $this;
	}

	final public function setEntityId(?int $entityId): self
	{
		$this->entityId = $entityId;

		return $this;
	}
}
