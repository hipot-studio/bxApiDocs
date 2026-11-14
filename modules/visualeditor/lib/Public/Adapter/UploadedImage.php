<?php

namespace Bitrix\VisualEditor\Public\Adapter;

final readonly class UploadedImage
{
	public function __construct(
		public string $name,
		public string $mimeType,
		public string $bytes,
	)
	{
	}
}
