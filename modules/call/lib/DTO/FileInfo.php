<?php

namespace Bitrix\Call\DTO;

class FileInfo extends Hydrator
{
	public int $id = 0;
	public string $url = '';
	public string $name = '';
	public string $mime = '';
	public int $size = 0;
	public int $duration = 0;
	public string $type = '';
}
