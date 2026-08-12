<?php

namespace Bitrix\Crm\Integration\Analytics\Builder;

class AnalyticsEventDto
{
	private ?string $event = null;
	private ?string $section = null;
	private ?string $subSection = null;
	private ?string $element = null;
	private ?string $status = null;
	private ?string $category = null;
	private ?string $type = null;
	private array $p2 = [];
	private array $p3 = [];
	private array $p4 = [];
	private array $p5 = [];

	public function setFromArray(array $fields): self
	{
		$fields = $this->normalizeCustomFields($fields);

		$this->event = $fields['event'] ?? $this->event;
		$this->section = $fields['section'] ?? $fields['c_section'] ?? $this->section;
		$this->subSection = $fields['subSection'] ?? $fields['c_sub_section'] ?? $this->subSection;
		$this->element = $fields['element'] ?? $fields['c_element'] ?? $this->element;
		$this->status = $fields['status'] ?? $this->status;
		$this->category = $fields['category'] ?? $this->category;
		$this->type = $fields['type'] ?? $this->type;
		$this->p2 = $fields['p2'] ?? $this->p2;
		$this->p3 = $fields['p3'] ?? $this->p3;
		$this->p4 = $fields['p4'] ?? $this->p4;
		$this->p5 = $fields['p5'] ?? $this->p5;

		return $this;
	}

	public function fillEventBuilder(AbstractBuilder $builder): void
	{
		$map = [
			'section' => fn($v) => $builder->setSection($v),
			'subSection' => fn($v) => $builder->setSubSection($v),
			'element' => fn($v) => $builder->setElement($v),
			'status' => fn($v) => $builder->setStatus($v),
			'p2' => fn($v) => $builder->setP2WithValueNormalization($v[0], $v[1]),
			'p3' => fn($v) => $builder->setP3WithValueNormalization($v[0], $v[1]),
			'p4' => fn($v) => $builder->setP4WithValueNormalization($v[0], $v[1]),
			'p5' => fn($v) => $builder->setP5WithValueNormalization($v[0], $v[1]),
		];

		foreach ($map as $field => $setter)
		{
			if ($this->$field !== null && $this->$field !== [])
			{
				$setter($this->$field);
			}
		}
	}

	public function isEmpty(): bool
	{
		return $this->event === null
			&& $this->section === null
			&& $this->subSection === null
			&& $this->element === null
			&& $this->status === null
			&& $this->category === null
			&& $this->type === null
			&& empty($this->p2)
			&& empty($this->p3)
			&& empty($this->p4)
			&& empty($this->p5);
	}

	public function getEvent(): ?string
	{
		return $this->event;
	}

	public function setEvent(?string $event): self
	{
		$this->event = $event;

		return $this;
	}

	public function getSection(): ?string
	{
		return $this->section;
	}

	public function setSection(?string $section): self
	{
		$this->section = $section;

		return $this;
	}

	public function getSubSection(): ?string
	{
		return $this->subSection;
	}

	public function setSubSection(?string $subSection): self
	{
		$this->subSection = $subSection;

		return $this;
	}

	public function getElement(): ?string
	{
		return $this->element;
	}

	public function setElement(?string $element): self
	{
		$this->element = $element;

		return $this;
	}

	public function getStatus(): ?string
	{
		return $this->status;
	}

	public function setStatus(?string $status): self
	{
		$this->status = $status;

		return $this;
	}

	public function getCategory(): ?string
	{
		return $this->category;
	}

	public function setCategory(?string $category): self
	{
		$this->category = $category;

		return $this;
	}

	public function getType(): ?string
	{
		return $this->type;
	}

	public function setType(?string $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function getP2(): array
	{
		return $this->p2;
	}

	public function setP2(array|string $p2): self
	{
		$this->p2 = $this->normalizeCustomField($p2);

		return $this;
	}

	public function getP3(): array
	{
		return $this->p3;
	}

	public function setP3(array|string $p3): self
	{
		$this->p3 = $this->normalizeCustomField($p3);

		return $this;
	}

	public function getP4(): array
	{
		return $this->p4;
	}

	public function setP4(array|string $p4): self
	{
		$this->p4 = $this->normalizeCustomField($p4);

		return $this;
	}

	public function getP5(): array
	{
		return $this->p5;
	}

	public function setP5(array|string $p5): self
	{
		$this->p5 = $this->normalizeCustomField($p5);

		return $this;
	}

	private function normalizeCustomFields(array $fields): array
	{
		$customFields = ['p2', 'p3', 'p4', 'p5'];
		foreach ($customFields as $field)
		{
			if (!isset($fields[$field]))
			{
				continue;
			}

			$fields[$field] = $this->normalizeCustomField($fields[$field]);
		}

		return $fields;
	}

	private function normalizeCustomField(mixed $field): array
	{
		if (is_string($field))
		{
			$parts = explode('_', $field);
			$field = $parts;
		}

		if (!is_array($field) || count($field) !== 2)
		{
			return [];
		}

		return $field;
	}
}
