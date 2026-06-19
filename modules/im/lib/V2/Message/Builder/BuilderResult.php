<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder;

use Bitrix\Im\V2\Message\Builder\Entity\Builder;
use Bitrix\Im\V2\Result;

class BuilderResult extends Result
{
	protected ?Builder $builder = null;

	public function getBuilder(): ?Builder
	{
		return $this->builder;
	}

	public function setBuilder(Builder $builder): self
	{
		$this->builder = $builder;

		return $this;
	}
}
