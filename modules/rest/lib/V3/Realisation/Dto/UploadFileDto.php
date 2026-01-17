<?php

namespace Bitrix\Rest\V3\Realisation\Dto;

use Bitrix\Main\Validation\Rule\AtLeastOnePropertyNotEmpty;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\OnlyOneOfPropertyRequired;
use Bitrix\Main\Validation\Rule\Url;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\UI\FileUploader\UploadResult;

#[AtLeastOnePropertyNotEmpty(['data', 'url'])]
#[OnlyOneOfPropertyRequired(['data', 'url'])]
final class UploadFileDto extends Dto
{
	#[NotEmpty]
	#[Required]
	public string $name;

	#[NotEmpty]
	public ?string $data; // base64 encoded file data

	#[Url]
	public ?string $url;
	private ?UploadResult $result;

	public function getResult(): ?UploadResult
	{
		return $this->result;
	}

	public function setResult(?UploadResult $result): UploadFileDto
	{
		$this->result = $result;
		return $this;
	}
}