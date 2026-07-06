<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlocksBuilder;
use Bitrix\Im\V2\Result;

class BuilderResult extends Result
{
	protected ?BlocksBuilder $builder = null;

	public function getBlocksBuilder(): ?BlocksBuilder
	{
		return $this->builder;
	}

	public function setBlocksBuilder(BlocksBuilder $builder): self
	{
		$this->builder = $builder;

		return $this;
	}
}
