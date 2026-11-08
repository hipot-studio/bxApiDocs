<?php

namespace Bitrix\Rest\V3\Interaction\Response;

class BatchResponse extends Response
{
	/**
	 * @var array[]
	 */
	private array $items = [];

	private array $context = [];

	public function addItem(int|string $alias, Response $item): void
	{
		$responseData = $item->toArray();
		if (!empty($responseData['item']))
		{
			$this->context[$alias] = $responseData['item'];
		}
		elseif (!empty($responseData['items']))
		{
			$this->context[$alias] = $responseData['items'];
		}
		$this->items[] = $responseData;
	}

	public function toArray(): array
	{
		return $this->items;
	}

	public function getContext(int|string|null $alias = null): array
	{
		return ($alias !== null && isset($this->context[$alias])) ? $this->context[$alias] : $this->context;
	}
}
