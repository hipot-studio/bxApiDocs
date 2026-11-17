<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Controller;

use Bitrix\Intranet\Integration\Templates\Air\HighContrast;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Response;

final class HighContrastMode extends Controller
{
	public function finalizeResponse(Response $response): void
	{
		if ($response instanceof HttpResponse)
		{
			$response->addHeader('Cache-Control', 'private, no-store');
		}
	}

	public function configureActions(): array
	{
		return [
			'set' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\ContentType([ActionFilter\ContentType::JSON]),
				],
			],
		];
	}

	public function setAction(): ?array
	{
		$request = $this->getRequest();
		if (array_key_exists('userId', $request->getQueryList()->toArray())
			|| array_key_exists('userId', $request->getPostList()->toArray()))
		{
			$this->addError(new Error('Invalid high contrast mode request', 'INVALID_ARGUMENT'));

			return null;
		}

		try
		{
			// Read the JSON value before action argument binding can coerce it to bool.
			$body = json_decode($request::getInput(), false, 512, JSON_THROW_ON_ERROR);
		}
		catch (\JsonException)
		{
			$body = null;
		}

		if (
			!($body instanceof \stdClass)
			|| array_keys(get_object_vars($body)) !== ['enabled']
			|| !is_bool($body->enabled)
		)
		{
			$this->addError(new Error('Invalid high contrast mode request', 'INVALID_ARGUMENT'));

			return null;
		}

		try
		{
			HighContrast::setEnabled($body->enabled);
		}
		catch (\RuntimeException $exception)
		{
			$this->addError(new Error($exception->getMessage(), 'WRITE_FAILED'));

			return null;
		}

		return ['enabled' => HighContrast::isEnabled()];
	}

	public function getAction(): array
	{
		return ['enabled' => HighContrast::isEnabled()];
	}
}
