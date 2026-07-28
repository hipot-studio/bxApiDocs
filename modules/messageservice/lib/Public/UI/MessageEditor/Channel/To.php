<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\MessageEditor\Channel;

use Bitrix\Main\Type\Dictionary;
use Bitrix\MessageService\Public\UI\MessageEditor\Channel\To\Appearance;

final class To implements \JsonSerializable
{
	private ?Dictionary $customData = null;

	public function __construct(
		private readonly string $id,
		private readonly string $value,
		private readonly Appearance $appearance,
	)
	{
	}

	public static function fromArray(array $data): ?self
	{
		$id = $data['id'] ?? null;
		$value = $data['value'] ?? null;
		$appearance = $data['appearance'] ?? null;

		if (!is_string($id) || $id === '' || !is_string($value) || !is_array($appearance))
		{
			return null;
		}

		$appearanceObject = Appearance::fromArray($appearance);
		if ($appearanceObject === null)
		{
			return null;
		}

		$self = new self($id, $value, $appearanceObject);

		if (isset($data['customData']) && is_array($data['customData']))
		{
			$self->getCustomData()->setValues($data['customData']);
		}

		return $self;
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getValue(): string
	{
		return $this->value;
	}

	public function getAppearance(): Appearance
	{
		return $this->appearance;
	}

	public function getCustomData(): Dictionary
	{
		$this->customData ??= new Dictionary();

		return $this->customData;
	}

	public function __clone()
	{
		if ($this->customData !== null)
		{
			$this->customData = clone $this->customData;
		}
	}

	public function jsonSerialize(): array
	{
		$result = [
			'id' => $this->id,
			'value' => $this->value,
			'appearance' => $this->appearance,
		];

		if ($this->customData !== null && !$this->customData->isEmpty())
		{
			$result['customData'] = $this->customData;
		}

		return $result;
	}
}
