<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Dictionary;

/**
 * A value type of a communication channel: the code an item stores the value under, its captions in
 * the language of the answer and whether it is one of the messengers CRM no longer offers.
 *
 * A caption is null when the language resources carry no phrase for the value, or carry a blank one;
 * an absent caption is not an error. An empty string is not a caption the contract provides for.
 */
final readonly class CommunicationType
{
	public function __construct(
		private string $id,
		private ?string $name,
		private ?string $nameShort,
		private bool $isDeprecated,
	)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function getNameShort(): ?string
	{
		return $this->nameShort;
	}

	public function isDeprecated(): bool
	{
		return $this->isDeprecated;
	}
}
