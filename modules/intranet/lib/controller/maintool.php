<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Controller;

use Bitrix\Intranet\Composite\CacheProvider;
use Bitrix\Intranet\Internal\MainTool\AccessPolicy;
use Bitrix\Intranet\Internal\MainTool\AvailabilityProvider;
use Bitrix\Intranet\Internal\MainTool\InitialShowService;
use Bitrix\Intranet\Internal\MainTool\Repository;
use Bitrix\Intranet\Internal\MainTool\SelectionService;
use Bitrix\Intranet\Internal\MainTool\StateProvider;
use Bitrix\Intranet\Portal\FirstPage;
use Bitrix\Main;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class MainTool extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
		];
	}

	public function configureActions(): array
	{
		return [
			'getState' => [
				'+prefilters' => [new ActionFilter\CloseSession()],
			],
		];
	}

	public function getStateAction(): ?array
	{
		if (!$this->checkFeature())
		{
			return ['isMainToolPickerEnabled' => false];
		}

		try
		{
			[$stateProvider] = $this->createServices();
			$state = $stateProvider->getState($this->getSiteId(), $this->getUserId());
			if (!$state['isMainToolPickerEnabled'])
			{
				$this->addLocalizedError('MAIN_TOOL_FEATURE_DISABLED', $state);
			}

			return $state;
		}
		catch (Main\AccessDeniedException)
		{
			$this->addLocalizedError('MAIN_TOOL_ACCESS_DENIED');
		}
		catch (\Throwable)
		{
			$this->addLocalizedError('MAIN_TOOL_SAVE_FAILED');
		}

		return null;
	}

	public function applyAction(): ?array
	{
		if (!$this->checkFeature())
		{
			return ['isMainToolPickerEnabled' => false];
		}

		$request = $this->readJsonRequest();
		if ($request === null)
		{
			return null;
		}
		if (!$this->isValidApplyRequest($request))
		{
			$this->addLocalizedError('MAIN_TOOL_INVALID_ARGUMENT');

			return null;
		}

		return $this->applySelection($request);
	}

	public function confirmInitialShowAction(): ?array
	{
		if (!$this->checkFeature())
		{
			return ['isMainToolPickerEnabled' => false];
		}

		try
		{
			$result = $this->createInitialShowService()->confirm($this->getSiteId(), $this->getUserId());
		}
		catch (Main\AccessDeniedException)
		{
			$this->addLocalizedError('MAIN_TOOL_ACCESS_DENIED');

			return null;
		}
		catch (\Throwable)
		{
			$this->addLocalizedError('MAIN_TOOL_SAVE_FAILED');

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addResultErrors($result);

			return null;
		}

		return $result->getData();
	}

	/** Adapts the existing setPreset action without exposing another HTTP action. */
	public function applyLegacyPreset(mixed $preset, mixed $mode): ?array
	{
		if (!$this->checkFeature())
		{
			return null;
		}
		if (!is_string($preset) || !in_array($mode, ['personal', 'global'], true))
		{
			$this->addLocalizedError('MAIN_TOOL_INVALID_ARGUMENT');

			return null;
		}

		$request = [
			'presetId' => $preset,
			'applyForAll' => $mode === 'global',
		];
		if (!$this->isValidApplyRequest($request))
		{
			$this->addLocalizedError('MAIN_TOOL_INVALID_ARGUMENT');

			return null;
		}

		return $this->applySelection($request);
	}

	/** @return array{StateProvider, SelectionService} */
	protected function createServices(): array
	{
		$repository = new Repository();
		$accessPolicy = new AccessPolicy();
		$availabilityProvider = new AvailabilityProvider();

		return [
			new StateProvider($repository, $availabilityProvider, $accessPolicy),
			new SelectionService($repository, $availabilityProvider, $accessPolicy),
		];
	}

	protected function getSiteId(): string
	{
		return (string)Main\Context::getCurrent()->getSite();
	}

	protected function createInitialShowService(): InitialShowService
	{
		return new InitialShowService(new Repository(), new AvailabilityProvider(), new AccessPolicy());
	}

	protected function getUserId(): int
	{
		return (int)($this->getCurrentUser() ?? CurrentUser::get())->getId();
	}

	protected function prepareSession(bool $forAll): void
	{
		if ($forAll && Main\ModuleManager::isModuleInstalled('bitrix24'))
		{
			Main\Application::getInstance()->getSession()->set('B24_SHOW_DEMO_LICENSE_HINT', 1);
		}

		// Match CloseSession without allowing lazy session reads to acquire the lock again.
		session_write_close();
	}

	protected function checkFeature(): bool
	{
		if (AccessPolicy::isFeatureEnabled())
		{
			return true;
		}

		$this->addLocalizedError('MAIN_TOOL_FEATURE_DISABLED', ['isMainToolPickerEnabled' => false]);

		return false;
	}

	private function readJsonRequest(): ?array
	{
		try
		{
			$request = $this->getRequest();
			$request->decodeJsonStrict();

			return $request->getJsonList()->toArray();
		}
		catch (\Throwable)
		{
			$this->addLocalizedError('MAIN_TOOL_INVALID_ARGUMENT');

			return null;
		}
	}

	private function isValidApplyRequest(array $request): bool
	{
		return is_string($request['presetId'] ?? null)
			&& $request['presetId'] !== ''
			&& strlen($request['presetId']) <= 16
			&& is_bool($request['applyForAll'] ?? null)
		;
	}

	private function applySelection(array $request): ?array
	{
		try
		{
			$this->prepareSession($request['applyForAll']);
			[, $selectionService] = $this->createServices();
			$result = $selectionService->apply($this->getSiteId(), $this->getUserId(), [
				'presetId' => $request['presetId'],
				'applyForAll' => $request['applyForAll'],
			]);
		}
		catch (Main\AccessDeniedException)
		{
			$this->addLocalizedError('MAIN_TOOL_ACCESS_DENIED');

			return null;
		}
		catch (\Throwable)
		{
			$this->addLocalizedError('MAIN_TOOL_SAVE_FAILED');

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addResultErrors($result);

			return null;
		}

		$this->onSelectionApplied($request, $result->getData()['clearAllFirstPages']);

		return ['url' => $result->getData()['url']];
	}

	protected function onSelectionApplied(array $request, bool $clearAllFirstPages): void
	{
		try
		{
			if ($clearAllFirstPages)
			{
				FirstPage::getInstance()->clearCacheForAll();
			}
			else
			{
				FirstPage::getInstance()->clearCache();
			}
		}
		catch (\Throwable $exception)
		{
			Main\Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}

		try
		{
			CacheProvider::deleteUserCache($this->getUserId());
		}
		catch (\Throwable $exception)
		{
			Main\Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}

		if ($request['applyForAll'])
		{
			try
			{
				$event = new Main\Event(
					'intranet',
					'onAfterChangeLeftMenuPreset',
					[
						'SITE_ID' => $this->getSiteId(),
						'VALUE' => $request['presetId'],
					]
				);
				Main\EventManager::getInstance()->send($event);
			}
			catch (\Throwable $exception)
			{
				Main\Application::getInstance()->getExceptionHandler()->writeToLog($exception);
			}
		}
	}

	private function addResultErrors(Main\Result $result): void
	{
		foreach ($result->getErrors() as $error)
		{
			$this->addLocalizedError((string)$error->getCode(), $error->getCustomData());
		}
	}

	private function addLocalizedError(string $code, mixed $customData = null): void
	{
		$this->addError(new Main\Error((string)Loc::getMessage($code), $code, $customData));
	}
}
