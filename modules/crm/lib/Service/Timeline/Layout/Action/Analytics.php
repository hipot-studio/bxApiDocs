<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Action;

use Bitrix\Crm\Service\Timeline\Layout\Base;

class Analytics extends Base
{
	private array $labels;
	private ?string $endpoint;

	public function __construct(array $labels = [], ?string $endpoint = null)
	{
		$this->labels = $labels;
		$this->endpoint = $endpoint;
	}

	public function setEndpoint(string $endpoint): self
	{
		$this->endpoint = $endpoint;

		return $this;
	}

	public function getEndpoint(): ?string
	{
		return $this->endpoint;
	}

	public function toArray(): array
	{
		$this->labels['hit'] = $this->getEndpoint();

		return $this->labels;
	}

	public function setEvent(string $event): Analytics
	{
		$this->labels['event'] = $event;

		return $this;
	}

	public function setElement(string $element): Analytics
	{
		$this->labels['c_element'] = $element;

		return $this;
	}

	public function setP2(string $p2): Analytics
	{
		$this->labels['p2'] = $p2;

		return $this;
	}

	public function setP4(string $p4): Analytics
	{
		$this->labels['p4'] = $p4;

		return $this;
	}

	public function setP5(string $p5): Analytics
	{
		$this->labels['p5'] = $p5;

		return $this;
	}
}
