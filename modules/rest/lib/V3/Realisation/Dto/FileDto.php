<?php

namespace Bitrix\Rest\V3\Realisation\Dto;

use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

final class FileDto extends Dto
{
	public ?string $id;

	public ?string $name;

	public ?string $url;

	#[Required]
	public UploadFileDto $upload;
}
