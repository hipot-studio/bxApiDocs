<?php

declare(strict_types=1);

use Bitrix\Disk\Internal\Service\UnifiedLink\Render\ErrorPageState;
use Bitrix\Intranet\Portal;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;

if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

if(!Loader::includeModule('disk'))
{
	return false;
}
class CDiskErrorPageComponent extends \Bitrix\Disk\Internals\BaseComponent
{
	protected function processActionDefault()
	{
		$state = $this->resolveState();
		$this->arResult = $this->buildResult($state);

		$status = $this->resolveStatus($state);
		if ($status !== null)
		{
			Bitrix\Main\Application::getInstance()
				->getContext()
				->getResponse()
				->setStatus($status)
			;
		}

		$this->includeComponentTemplate();
	}

	private function resolveState(): ErrorPageState
	{
		$state = $this->arParams['STATE'] ?? null;

		return is_string($state)
			? ErrorPageState::tryFrom($state) ?? ErrorPageState::AccessDenied
			: ErrorPageState::AccessDenied
		;
	}

	/**
	 * @return array{STATE: string, IS_ADMIN?: bool, SETTINGS_URL?: ?string, VIEW_URL?: ?string}
	 */
	private function buildResult(ErrorPageState $state): array
	{
		if ($state === ErrorPageState::HtmlSessionBusy)
		{
			$viewUrl = $this->arParams['VIEW_URL'] ?? null;
			if (
				!is_string($viewUrl)
				|| !preg_match(
					'~\A/html/(?:[a-zA-Z0-9_-]|%[a-fA-F0-9]{2})+(?:\?attachedId=[1-9][0-9]*)?\z~',
					$viewUrl,
				)
			)
			{
				$viewUrl = null;
			}

			return ['STATE' => $state->value, 'VIEW_URL' => $viewUrl];
		}

		if ($state === ErrorPageState::AccessDenied)
		{
			return ['STATE' => ErrorPageState::AccessDenied->value];
		}
		if ($state === ErrorPageState::EditFormatNotSupported)
		{
			return ['STATE' => ErrorPageState::EditFormatNotSupported->value];
		}

		$isAdmin = CurrentUser::get()->isAdmin();
		$settingsUrl = null;
		if ($isAdmin && Loader::includeModule('intranet'))
		{
			$settingsUri = new Uri(Portal::getInstance()->getSettings()->getSettingsUrl());
			$settingsUri->addParams([
				'page' => 'communication',
				'option' => 'settings-communication-section-disk',
			]);
			$settingsUrl = (string)$settingsUri;
		}

		return [
			'STATE' => ErrorPageState::EditNotSupported->value,
			'IS_ADMIN' => $isAdmin,
			'SETTINGS_URL' => $settingsUrl,
		];
	}

	private function resolveStatus(ErrorPageState $state): ?string
	{
		if ($state !== ErrorPageState::AccessDenied)
		{
			return null;
		}

		return Loader::includeModule('bitrix24') ? '403 Forbidden' : '404 Not Found';
	}
}
