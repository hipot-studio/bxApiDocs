<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Controller;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubNavigationProvider;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\SettingsHubBootstrapProvider;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\SettingsHubProvider;
use Bitrix\Crm\V2\Public\Command\Settings\UpdateSettingCommand;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use RuntimeException;
use Throwable;

final class SettingsHub extends Controller
{
	public const ERROR_PRESENTATION_REFRESH_FAILED = 'SETTINGS_PRESENTATION_REFRESH_FAILED';

	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new ActionFilter\Scope(ActionFilter\Scope::AJAX),
			new ActionFilter\ContentType([ActionFilter\ContentType::JSON]),
		];
	}

	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	/** @param mixed $value Native mixed is rejected by the Engine argument binder. */
	public function updateAction(string $id, $value, ?string $eventId = null): ?array
	{
		if ($eventId !== null && preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $eventId) !== 1)
		{
			$this->addError(new Error('Invalid eventId', ErrorCode::INVALID_ARG_VALUE));

			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		$result = (new UpdateSettingCommand($id, $value, $userId, $eventId))->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		try
		{
			/** @var SettingsHubBootstrapProvider $provider */
			$provider = ServiceLocator::getInstance()->get(SettingsHubBootstrapProvider::class);
			$presentation = $provider->get(Container::getInstance()->getUserPermissions($userId));
		}
		catch (Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
			$this->addPresentationRefreshError();

			return null;
		}

		if (!$presentation->isSuccess())
		{
			if (!in_array($presentation->getError()?->getCode(), [
				SettingsHubProvider::ERROR_BUILD_FAILED,
				SettingsHubNavigationProvider::ERROR_BUILD_FAILED,
			], true))
			{
				Application::getInstance()->getExceptionHandler()->writeToLog(
					new RuntimeException(implode('; ', $presentation->getErrorMessages())),
				);
			}
			$this->addPresentationRefreshError();

			return null;
		}

		return ['presentation' => $presentation->getData()];
	}

	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function listAction(): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$permissions = Container::getInstance()->getUserPermissions($userId);
		if (!$permissions->entityType()->canReadSomeItemsInCrm())
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$provider = ServiceLocator::getInstance()->get(SettingsHubProvider::class);
		$presentation = $provider->getList($permissions);
		if (!$presentation->isSuccess())
		{
			$this->addErrors($presentation->getErrors());

			return null;
		}

		return ['presentation' => $presentation->getData()];
	}

	private function addPresentationRefreshError(): void
	{
		$this->addError(new Error(
			'Failed to refresh the settings presentation after saving.',
			self::ERROR_PRESENTATION_REFRESH_FAILED,
		));
	}
}
