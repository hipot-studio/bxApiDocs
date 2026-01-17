<?php

namespace Bitrix\Mail\Helper\Entity\Department;

use Bitrix\Mail\Helper\Entity\Entity;

final class Department extends Entity
{
	private int $id;
	private string $name;
	private string $accessCode;
	protected string $type = 'DEPARTMENT';

	public function __construct(array $nodeData)
	{
		$this->id = $nodeData['ID'] ?? 0;
		$this->name = $nodeData['NAME'] ?? '';
		$this->accessCode = $nodeData['ACCESS_CODE'] ?? '';
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getAccessCode(): string
	{
		return $this->accessCode;
	}



	public function getType(): string
	{
		return $this->type;
	}

	public function getUniqueKeyValue(): string
	{
		return $this->getAccessCode();
	}

	/**
	 * @return array{
	 *     id: int,
	 *     name: string,
	 *     type: string,
	 * }
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'type' => $this->type,
		];
	}
}
