<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response;

use Bitrix\Crm\EO_Status;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final readonly class Stage implements JsonSerializable, Arrayable
{
	public function __construct(
		private string $id,
		private string $title,
		private string $color,
		private int $sort,
		private string $semantics,
	)
	{
	}

	public static function fromObject(EO_Status $stage): self
	{
		return new self(
			id: $stage->getStatusId(),
			title: $stage->getName(),
			color: ($stage->getColor() ?? ''),
			sort: $stage->getSort(),
			semantics: ($stage->getSemantics() ?? PhaseSemantics::PROCESS),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'color' => $this->color,
			'sort' => $this->sort,
			'semantics' => $this->semantics,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}