<?php

namespace Bitrix\Disk\Uf\Integration;

use Bitrix\Main\HttpRequest;

class UploadRequestSizeResolver
{
	public function resolve(HttpRequest $request): int
	{
		$contentRangeSize = $this->resolveContentRangeSize($request);
		if ($contentRangeSize > 0)
		{
			return $contentRangeSize;
		}

		$requestSize = (int)$request->get('size');
		if ($requestSize > 0)
		{
			return $requestSize;
		}

		$contentLength = (int)$request->getHeader('Content-Length');

		return $contentLength > 0 ? $contentLength : 0;
	}

	private function resolveContentRangeSize(HttpRequest $request): int
	{
		$contentRange = $request->getHeader('Content-Range');
		if ($contentRange === null || !preg_match('/(\d+)-(\d+)\/(\d+)$/', $contentRange, $match))
		{
			return 0;
		}

		[$startRange, $endRange, $fileSize] = [(int)$match[1], (int)$match[2], (int)$match[3]];
		if ($startRange > $endRange || $fileSize <= $endRange)
		{
			return 0;
		}

		return $fileSize;
	}
}
