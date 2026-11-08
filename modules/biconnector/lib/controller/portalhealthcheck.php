<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Internal\PortalHealthcheck\DiagnosticService;
use Bitrix\BIConnector\Internal\PortalHealthcheck\Snapshot;
use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;

class PortalHealthcheck extends Controller
{
	public function getAction(): ?array
	{
		if (!$this->checkAccess())
		{
			return null;
		}

		return $this->response($this->readSnapshot());
	}

	#[HttpMethod([\Bitrix\Main\Engine\ActionFilter\HttpMethod::METHOD_POST])]
	public function startAction(): ?array
	{
		if (!$this->checkAccess())
		{
			return null;
		}

		return $this->response($this->startDiagnosis());
	}

	protected function isBox(): bool
	{
		return !Loader::includeModule('bitrix24');
	}

	protected function isAdmin(): bool
	{
		return CurrentUser::get()->isAdmin();
	}

	protected function isFeatureEnabled(): bool
	{
		return Feature::isPortalHealthcheckEnabled();
	}

	protected function isCloudMode(): bool
	{
		return SupersetHostMode::isCloud();
	}

	protected function readSnapshot(): Snapshot
	{
		return (new DiagnosticService())->getSnapshot();
	}

	protected function startDiagnosis(): Snapshot
	{
		return (new DiagnosticService())->start();
	}

	private function response(Snapshot $snapshot): array
	{
		return array_replace(['nodes' => []], $snapshot->toArray());
	}

	private function checkAccess(): bool
	{
		if (!$this->isBox() || !$this->isAdmin())
		{
			$this->addError(new Error('', 'ACCESS_DENIED'));

			return false;
		}

		if (!$this->isFeatureEnabled() || !$this->isCloudMode())
		{
			$this->addError(new Error('', 'HEALTHCHECK_MODE_UNAVAILABLE'));

			return false;
		}

		return true;
	}
}
