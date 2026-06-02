<?php

declare(strict_types=1);

namespace Bitrix\AI\Services;

use Bitrix\AI\Facade\Portal;
use Bitrix\AI\Tuning\Defaults;
use Bitrix\AI\Tuning\Manager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Loader;
use Bitrix\Main\Request;
use Bitrix\Main\Web\Json;
use CBitrix24;

class BitrixGptAgreementService
{
	private const MODULE_AI_ID = 'ai';
	private const MODULE_AIASSISTANT_ID = 'aiassistant';
	private const OPTION_AGREEMENT_STATUS = 'bitrixgpt_agreement_status';
	private const OPTION_AGREEMENT_ACCEPTED_USER_ID = 'bitrixgpt_agreement_accepted_user_id';
	private const OPTION_AGREEMENT_ACCEPTED_AT = 'bitrixgpt_agreement_accepted_at';
	private const OPTION_POPUP_SHOW_COUNT = 'bitrixgpt_agreement_popup_show_count';
	private const OPTION_POPUP_SKIP_UNTIL = 'bitrixgpt_agreement_popup_skip_until';
	private const OPTION_TUNING = 'tuning';
	private const AIASSISTANT_SCENARIO_OPTIONS = [
		'remote_mcp_server_active',
		'remote_mcp_server_sharing_only',
		'enable_bitrix_internal_mcp_server',
	];
	private const AGREEMENT_ACCEPTED = 'Y';
	private const AGREEMENT_DECLINED = 'N';
	private const SHOW_LIMIT = 2;
	private const POPUP_SKIP_SECONDS = 86400;
	private const PORTAL_AGE_FOR_SKIP = 3 * 86400;

	private const DISALLOWED_PAGES = [
		'/start/',
		'/bitrix/',
	];

	public function getPopupDataForAutoShow(): ?array
	{
		$request = Context::getCurrent()->getRequest();
		if (!$this->checkRequest($request) || !$this->checkNeedToShowPopup() || !$this->hasEnabledScenario())
		{
			return null;
		}

		return $this->getPopupData();
	}

	public function onSaveAISettings(): ?array
	{
		if (!$this->isBelarusPortal() || !$this->checkNeedToShowPopup())
		{
			return null;
		}

		return $this->getPopupData();
	}

	public function getPopupAttemptForDisplay(): ?int
	{
		return (int)Option::get(self::MODULE_AI_ID, self::OPTION_POPUP_SHOW_COUNT, 0);
	}

	public function acceptAgreement(): void
	{
		Option::set(self::MODULE_AI_ID, self::OPTION_AGREEMENT_STATUS, self::AGREEMENT_ACCEPTED);
		Option::set(
			self::MODULE_AI_ID,
			self::OPTION_AGREEMENT_ACCEPTED_USER_ID,
			(string)CurrentUser::get()->getId(),
		);
		$acceptedAt = time();
		Option::set(self::MODULE_AI_ID, self::OPTION_AGREEMENT_ACCEPTED_AT, (string)$acceptedAt);
	}

	public function declineAgreement(): void
	{
		Option::set(self::MODULE_AI_ID, self::OPTION_AGREEMENT_STATUS, self::AGREEMENT_DECLINED);
		$this->disableAllScenarios();
	}

	public function isAvailableForExternalRequest(): bool
	{
		if (
			!$this->isBelarusPortal()
			|| !Loader::includeModule('bitrix24')
			|| ($this->getAgreementStatus() === self::AGREEMENT_ACCEPTED)
		)
		{
			return true;
		}

		return false;
	}

	public function skipAgreementPopup(): void
	{
		$attempt = $this->getPopupAttemptForDisplay();
		if (($attempt >= self::SHOW_LIMIT) || !$this->isOldPortal())
		{
			return;
		}

		$this->incrementPopupAttemptForDisplay();
		$skipUntil = time() + self::POPUP_SKIP_SECONDS;
		Option::set(self::MODULE_AI_ID, self::OPTION_POPUP_SKIP_UNTIL, (string)$skipUntil);
	}

	private function checkRequest(Request | HttpRequest $request): bool
	{
		if (
			!$this->isBelarusPortal()
			|| $this->isIframeRequest($request)
			|| !$this->isHtmlRequest($request)
			|| $this->isDisallowedPage()
		)
		{
			return false;
		}

		return true;
	}

	private function isDisallowedPage(): bool
	{
		global $APPLICATION;
		$currPage = $APPLICATION->GetCurPage();

		foreach (self::DISALLOWED_PAGES as $page)
		{
			if (str_starts_with($currPage, $page))
			{
				return true;
			}
		}

		return false;
	}

	private function checkNeedToShowPopup(): bool
	{
		global $USER;
		$isAdmin = Loader::includeModule('bitrix24') ? CBitrix24::IsPortalAdmin((int)$USER->GetID()) : $USER->isAdmin();

		if (
			!Loader::includeModule('bitrix24')
			|| !$isAdmin
			|| ($this->getAgreementStatus() === self::AGREEMENT_ACCEPTED)
			|| $this->isPopupSkipActive()
		)
		{
			return false;
		}

		return true;
	}

	private function getPopupData(): array
	{
		$attempt = $this->getPopupAttemptForDisplay();

		return [
			'attempt' => $attempt,
			'showLimit' => self::SHOW_LIMIT,
			'showSkip' => $this->isOldPortal() && ($attempt < self::SHOW_LIMIT),
		];
	}

	public function isOldPortal(): bool
	{
		$portalCreateDate = Portal::getCreationDateTime();

		if (!$portalCreateDate)
		{
			return true;
		}

		return (time() - $portalCreateDate->getTimestamp()) >= self::PORTAL_AGE_FOR_SKIP;
	}

	private function isIframeRequest(HttpRequest $request): bool
	{
		return ($request->get('IFRAME') === 'Y') || ($request->get('iframe') === 'Y');
	}

	private function isHtmlRequest(HttpRequest $request): bool
	{
		$accept = $request->getHeader('Accept');

		return ($accept !== null) && str_contains($accept, 'text/html');
	}

	private function incrementPopupAttemptForDisplay(): int
	{
		$count = (int)$this->getPopupAttemptForDisplay();

		$count++;
		Option::set(self::MODULE_AI_ID, self::OPTION_POPUP_SHOW_COUNT, (string)$count);

		return $count;
	}

	private function getAgreementStatus(): ?string
	{
		$value = Option::get(self::MODULE_AI_ID, self::OPTION_AGREEMENT_STATUS);
		$hasAgreementStatus = in_array(
			$value,
			[self::AGREEMENT_ACCEPTED, self::AGREEMENT_DECLINED],
			true,
		);

		return $hasAgreementStatus ? $value : null;
	}

	private function isPopupSkipActive(): bool
	{
		$skipUntil = (int)Option::get(self::MODULE_AI_ID, self::OPTION_POPUP_SKIP_UNTIL, 0);

		return $skipUntil > time();
	}

	private function isBelarusPortal(): bool
	{
		return Portal::getRegion() === 'by';
	}

	private function hasEnabledScenario(): bool
	{
		$manager = new Manager();
		foreach ($manager->getList() as $group)
		{
			foreach ($group->getItems() as $item)
			{
				if (Defaults::isItemInternal($item))
				{
					continue;
				}

				if ($item->isBoolean() && $item->getValue() === true)
				{
					return true;
				}
			}
		}

		if (Loader::includeModule(self::MODULE_AIASSISTANT_ID))
		{
			foreach (self::AIASSISTANT_SCENARIO_OPTIONS as $optionName)
			{
				if (Option::get(self::MODULE_AIASSISTANT_ID, $optionName, 'N') === 'Y')
				{
					return true;
				}
			}
		}

		return false;
	}

	public function disableAllScenarios(): void
	{
		$manager = new Manager();
		$storage = Manager::getTuningStorage();

		foreach ($manager->getList() as $group)
		{
			foreach ($group->getItems() as $item)
			{
				if (Defaults::isItemInternal($item) || !$item->isBoolean())
				{
					continue;
				}

				$storage[$item->getCode()] = false;
			}
		}

		Option::set(self::MODULE_AI_ID, self::OPTION_TUNING, Json::encode($storage));

		if (Loader::includeModule(self::MODULE_AIASSISTANT_ID))
		{
			foreach (self::AIASSISTANT_SCENARIO_OPTIONS as $optionName)
			{
				Option::set(self::MODULE_AIASSISTANT_ID, $optionName, 'N');
			}
		}
	}
}
