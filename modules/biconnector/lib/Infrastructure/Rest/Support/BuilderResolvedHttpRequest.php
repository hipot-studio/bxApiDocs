<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Support;

use Bitrix\Main\HttpRequest;

final class BuilderResolvedHttpRequest extends HttpRequest
{
	public static function fromDecoded(HttpRequest $source, array $decoded): self
	{
		$request = new self(
			$source->getServer(),
			$source->getQueryList()->toArray(),
			$source->getPostList()->toArray(),
			$source->getFileList()->toArray(),
			$source->getCookieRawList()->toArray(),
			$decoded,
		);
		foreach ($source->getHeaders()->toArray() as $name => $value)
		{
			$request->getHeaders()->set($name, $value);
		}

		return $request;
	}

	public static function getInput(): string
	{
		return '';
	}
}
