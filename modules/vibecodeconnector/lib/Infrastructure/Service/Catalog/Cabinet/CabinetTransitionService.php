<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Service\Catalog\Cabinet;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Localization\Loc;
use Bitrix\Vibecodeconnector\Infrastructure\Service\Catalog\OpenApp\OpenAppLayoutRenderer;
use Bitrix\Vibecodeconnector\Internal\Config\Feature\CabinetSeamlessEntry;
use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\OpenApp\OpenAppPayload;
use Bitrix\Vibecodeconnector\Internal\Exception\CabinetTransitionException;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Cabinet\CabinetPayloadBuilder;

final class CabinetTransitionService
{
	private const ERROR_DISABLED = 'CABINET_DISABLED';
	private const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';
	private const ERROR_BUILD_FAILED = 'CABINET_BUILD_FAILED';

	public function __construct(
		private readonly CabinetPayloadBuilder $payloadBuilder = new CabinetPayloadBuilder(),
		private readonly OpenAppLayoutRenderer $renderer = new OpenAppLayoutRenderer(),
	) {
	}

	public function renderPage(int $userId, ?string $target = null): string
	{
		$payload = $this->resolvePayload($userId, $target);
		if (is_string($payload))
		{
			return $this->renderer->renderErrorPage(...$this->errorParams($payload));
		}

		return $this->renderer->renderTopLevelPage($payload);
	}

	public function renderPageResponse(int $userId, ?string $target = null): HttpResponse
	{
		$response = new HttpResponse();
		$response->addHeader('Content-Type', 'text/html; charset=UTF-8');
		$response->addHeader('Cache-Control', 'private, no-store');
		$response->setContent($this->renderPage($userId, $target));

		return $response;
	}

	private function resolvePayload(int $userId, ?string $target): OpenAppPayload|string
	{
		if (!Feature::isEnabled(CabinetSeamlessEntry::class))
		{
			return self::ERROR_DISABLED;
		}

		if ($userId <= 0)
		{
			return self::ERROR_ACCESS_DENIED;
		}

		try
		{
			return $this->payloadBuilder->build($userId, $target);
		}
		catch (CabinetTransitionException $exception)
		{
			return $exception->getErrorCode();
		}
		catch (\Throwable)
		{
			return self::ERROR_BUILD_FAILED;
		}
	}

	/**
	 * @return array{string, string, string} error code, title and message
	 */
	private function errorParams(string $code): array
	{
		return [
			$code,
			(string)Loc::getMessage('VIBECODECONNECTOR_CABINET_ERROR_TITLE'),
			(string)(
				Loc::getMessage('VIBECODECONNECTOR_CABINET_ERROR_' . $code)
				?: Loc::getMessage('VIBECODECONNECTOR_CABINET_ERROR_DEFAULT')
			),
		];
	}
}
