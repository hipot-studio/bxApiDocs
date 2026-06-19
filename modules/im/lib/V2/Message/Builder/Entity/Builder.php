<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity;

class Builder implements \JsonSerializable
{
	public function __construct(
		public readonly Config $config,
		public readonly BlockCollection $blockCollection,
	)
	{}

	public function jsonSerialize(): array
	{
		$result = $this->config->jsonSerialize();
		$result['blocks'] = $this->blockCollection->jsonSerialize();

		return $result;
	}

	public function getPayloadText(): string
	{
		return $this->blockCollection->getPayloadText();
	}
}
