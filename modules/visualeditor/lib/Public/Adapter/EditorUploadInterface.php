<?php

namespace Bitrix\VisualEditor\Public\Adapter;

use Bitrix\Main\Result;

interface EditorUploadInterface
{
	public function upload(array $context, string $page, UploadedImage $file): Result;
}
