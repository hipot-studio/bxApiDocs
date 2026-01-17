<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule;

use Bitrix\Main\Validation\Rule\NotEmpty;

class OutputExpressionDto extends BaseExpressionDto
{
	#[NotEmpty]
	public ?string $portId;
	#[NotEmpty]
	public ?string $title;

	public function __construct(?string $portId, ?string $title)
	{
		$this->portId = $portId;
		$this->title = $title;
	}

	public function jsonSerialize(): array
	{
		return [
			'portId' => $this->portId,
			'title' => $this->title,
		];
	}

	public static function fromArray(array $data): self
	{
		return new self(
			$data['portId'] ?? null,
			$data['title'] ?? null,
		);
	}
}
