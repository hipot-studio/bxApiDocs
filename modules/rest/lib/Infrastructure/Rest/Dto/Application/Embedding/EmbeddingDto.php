<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Dto\Application\Embedding;

use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

class EmbeddingDto extends Dto
{
	#[Title('ID')]
	public int $id;

	#[Title('User ID')]
	public int $userId;

	#[Title('Placement name')]
	public string $placement;

	#[Title('Placement Handler URI')]
	public string $handler;

	public ?string $title = null;

	public ?string $description = null;

	public ?string $groupName = null;

	public ?string $additional = null;

	public ?array $options = null;

	public DtoCollection $languages;

	public function __construct()
	{
		$this->languages = new DtoCollection(EmbeddingLanguageDto::class);
		
		parent::__construct();
	}
}
