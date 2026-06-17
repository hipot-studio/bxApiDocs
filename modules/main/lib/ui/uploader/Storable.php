<?php

namespace Bitrix\Main\UI\Uploader;

use Bitrix\Main\Result;

interface Storable
{
	/**
	 * @param $path
	 * @param array $file
	 * @return Result
	 */
	public function copy($path, array $file);
}
