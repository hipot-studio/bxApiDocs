<?php

declare(strict_types=1);

namespace Bitrix\Crm\Dto\Caster;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Main\Type\DateTime;
use Throwable;

final class DateTimeCaster extends Caster
{
	private ?string $format = null;

	public function setFormat(?string $format): self
	{
		$this->format = $format;

		return $this;
	}

	protected function castSingleValue(mixed $value): ?DateTime
	{
		if (!is_scalar($value))
		{
			return null;
		}

		try {
			return new DateTime($value, $this->format);
		}
		catch (Throwable)
		{
			return null;
		}
	}
}
