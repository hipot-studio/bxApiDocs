<?php

namespace Bitrix\Intranet\License\Widget\Content;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Internal\Integration\Main\Integrator\IntegratorInfoDto;
use Bitrix\Intranet\Internal\Integration\Main\Integrator\IntegratorInfoService;
use Bitrix\Main\Application;
use Bitrix\Main\License\UrlProvider;
use Bitrix\Main\Localization\Loc;

class Integrator extends BaseContent
{
	private IntegratorInfoDto $integratorInfo;
	private \Bitrix\Main\License $license;

	public function __construct()
	{
		$this->integratorInfo = IntegratorInfoService::createByDefault()->getIntegratorInfo();
		$this->license = Application::getInstance()->getLicense();
	}

	public function getName(): string
	{
		return 'integrator';
	}

	public function getConfiguration(): array
	{
		if (!$this->license->isCis())
		{
			return [
				'isAvailable' => false,
			];
		}

		return [
			'isAvailable' => true,
			'isConnected' => $this->isIntegratorConnected(),
			'title' => $this->getTitle(),
			'description' => $this->getDescription(),
			'integratorName' => $this->integratorInfo->name ?? '',
			'integratorUrl' => $this->integratorInfo->id ? (new UrlProvider())->getPublicDomain()->setPath('/partners/partner/' . $this->integratorInfo->id) : '',
			'integratorLogo' => $this->integratorInfo->logo ?? '',
			'isCurrentUserAdmin' => CurrentUser::get()->canDoOperation('bitrix24_config'),
			'isCurrentUserIntegrator' => false,
			'buttons' => $this->getButtons(),
			'feedbackFormPresets' => $this->getFeedbackFormPresets(),
			'connectPartnerFormParams' => $this->getConnectPartnerFormParams(),
		];
	}

	private function getFeedbackFormPresets(): array
	{
		return [
			'source' => 'intranet.license-widget.partner-feedback',
			'user_id' => CurrentUser::get()->getId(),
			'user_email' => CurrentUser::get()->getEmail(),
			'is_admin' => CurrentUser::get()->isAdmin() ? 'Y' : 'N',
			'user_phone' => CurrentUser::get()->getPhoneNumber(),
		];
	}

	private function getConnectPartnerFormParams(): array
	{
		return [
			'partnerId' => $this->integratorInfo->id ?? '',
			'partnerName' => $this->integratorInfo->name ?? '',
			'partnerUrl' => $this->integratorInfo->url ?? '#',
			'partnerLogo' => $this->integratorInfo->logo ?? '',
			'publicDomain' => (new UrlProvider())->getPublicDomain(),
			'partnerPhone' => $this->integratorInfo->phone ?? '',
			'partnerCompany' => $this->integratorInfo->company ?? '',
			'partnerEmail' => $this->integratorInfo->email ?? '',
			'messages' => Loc::loadLanguageFile($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/intranet/install/templates/bitrix24/partner-connect-form.php'),
		];
	}

	private function getTitle(): string
	{
		if ($this->isIntegratorConnected() && !empty($this->integratorInfo->name))
		{
			return $this->integratorInfo->name;
		}

		return Loc::getMessage('INTRANET_LICENSE_WIDGET_PARTNER_NAME') ?? '';
	}

	private function getDescription(): string
	{
		return $this->isIntegratorConnected()
			? Loc::getMessage('INTRANET_LICENSE_WIDGET_PARTNER_NAME') ?? ''
			: Loc::getMessage('INTRANET_LICENSE_WIDGET_PARTNER_DESCRIPTION') ?? '';
	}

	private function isIntegratorConnected(): bool
	{
		return $this->integratorInfo->id > 0;
	}

	private function getButtons(): array
	{
		return $this->isIntegratorConnected()
			? $this->getButtonsForConnectedState()
			: $this->getButtonsForDisconnectedState();
	}

	private function getButtonsForConnectedState(): array
	{
		return [
			'connect' => [
				'title' => Loc::getMessage('INTRANET_LICENSE_WIDGET_PARTNER_CONTACT_BTN'),
			],
			'menu' => [
				'feedback' => [
					'title' => Loc::getMessage('INTRANET_LICENSE_WIDGET_PARTNER_FEEDBACK_BTN'),
				],
				'discontinue' => [
					'title' => Loc::getMessage('INTRANET_LICENSE_WIDGET_PARTNER_DISCONTINUE_BTN'),
				],
			],
		];
	}

	private function getButtonsForDisconnectedState(): array
	{
		return [
			'choose' => [
				'title' => Loc::getMessage('INTRANET_LICENSE_WIDGET_PARTNER_CHOOSE_BTN'),
				'landingCode' => 'info_implementation_request',
			],
		];
	}
}
