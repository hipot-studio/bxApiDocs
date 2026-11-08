<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Service\Embed;

use Bitrix\Main\Localization\Loc;
use Bitrix\Vibecodeconnector\Internal\Dto\Embed\SignedFormPayload;
use Bitrix\Vibecodeconnector\Internal\Service\Embed\EmbedCoworkAdminSettings;
use Bitrix\Vibecodeconnector\Internal\Service\Embed\EmbedCreateGuard;
use Bitrix\Vibecodeconnector\Internal\Service\Embed\EmbedCreateSettings;
use Bitrix\Vibecodeconnector\Internal\Service\Embed\EmbedOpenPayloadBuilder;
use Bitrix\Vibecodeconnector\Internal\Service\Embed\EmbedView;
use Bitrix\Vibecodeconnector\Internal\Service\Embed\EmbedViewSwitch;

final class EmbedOpenLayoutService
{
	private const ERROR_BUILD_FAILED = 'BUILD_FAILED';
	private const ERROR_BAD_VIEW = 'BAD_VIEW';
	private const ERROR_CREATE_DISABLED = 'CREATE_DISABLED';
	private const ERROR_SCREEN_DISABLED = 'SCREEN_DISABLED';

	private const SURFACE = 'embed-create';

	private readonly EmbedViewSwitch $viewSwitch;

	public function __construct(
		private readonly EmbedCreateGuard $guard = new EmbedCreateGuard(),
		private readonly EmbedCreateSettings $settings = new EmbedCreateSettings(),
		private readonly EmbedOpenPayloadBuilder $payloadBuilder = new EmbedOpenPayloadBuilder(),
		private readonly SignedFormRenderer $renderer = new SignedFormRenderer(self::SURFACE),
		EmbedCoworkAdminSettings $coworkAdminSettings = new EmbedCoworkAdminSettings(),
	) {
		$this->viewSwitch = new EmbedViewSwitch($settings, $coworkAdminSettings);
	}

	public function render(string $view, int $userId): string
	{
		return $this->open($view, $userId)['html'];
	}

	public function open(string $view, int $userId): array
	{
		$error = $this->resolveError($view, $userId);
		if ($error !== null)
		{
			return $this->errorResult($error, $view);
		}

		$payload = $this->buildPayload(EmbedView::from($view), $userId);

		return $payload === null
			? $this->errorResult(self::ERROR_BUILD_FAILED, $view)
			: ['html' => $this->renderer->renderForm($payload), 'origin' => $payload->origin()];
	}

	private function errorResult(string $code, string $view): array
	{
		return ['html' => $this->renderer->renderError(...$this->errorParams($code, $view)), 'origin' => ''];
	}

	private function resolveError(string $view, int $userId): ?string
	{
		$embedView = EmbedView::tryFrom($view);

		if ($embedView === null)
		{
			return $this->settings->isEnabled() ? self::ERROR_BAD_VIEW : self::ERROR_CREATE_DISABLED;
		}

		if (!$this->viewSwitch->isEnabled($embedView))
		{
			return $embedView === EmbedView::CoworkAdmin
				? self::ERROR_SCREEN_DISABLED
				: self::ERROR_CREATE_DISABLED
			;
		}

		return $this->guard->resolveErrorCode($embedView, $userId);
	}

	private function buildPayload(EmbedView $view, int $userId): ?SignedFormPayload
	{
		try
		{
			return $this->payloadBuilder->build($view, $userId);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * @return array{string, string, string}
	 */
	private function errorParams(string $code, string $view): array
	{
		$scope = EmbedView::tryFrom($view) === EmbedView::CoworkAdmin ? 'SCREEN' : 'CREATE';

		return [
			$code,
			(string)Loc::getMessage('VIBECODECONNECTOR_EMBED_' . $scope . '_ERROR_TITLE'),
			(string)(
				Loc::getMessage('VIBECODECONNECTOR_EMBED_' . $scope . '_ERROR_' . $code)
				?: Loc::getMessage('VIBECODECONNECTOR_EMBED_CREATE_ERROR_' . $code)
				?: Loc::getMessage('VIBECODECONNECTOR_EMBED_' . $scope . '_ERROR_DEFAULT')
			),
		];
	}
}
