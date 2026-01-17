<?php

namespace Bitrix\TasksMobile\Internal\Dto\Task;

use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Mobile\Dto\Dto;
use Bitrix\Mobile\Dto\Type;
use Bitrix\TasksMobile\Dto\DiskFileDto;

final class ResultDto extends Dto
{
	public int $id;
	public int $taskId;
	public ?int $messageId;
	public int $authorId;
	public int $createdAtTs;
	public string $status;
	public string $text;
	/** @var DiskFileDto[] */
	public array $files = [];

	public function getCasts(): array
	{
		return [
			'files' => Type::collection(DiskFileDto::class),
		];
	}

	protected function getDecoders(): array
	{
		return [
			function (array $fields): array {
				$converter = new Converter(Converter::KEYS | Converter::TO_CAMEL | Converter::LC_FIRST);

				return $converter->process($fields);
			},
		];
	}
}
