<?php

namespace Bitrix\Sign\Config;

use Bitrix\Main\Config\Option;
use Bitrix\Main;

final class Feature
{
	private static ?self $instance = null;

	public static function instance(): self
	{
		if (!self::$instance)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function isSendDocumentByEmployeeEnabled(?string $region = null): bool
	{
		$region = $region ?? Main\Application::getInstance()->getLicense()->getRegion();

		$publicitySettings = $this->read('service.b2e.init-by-employee.publicity');
		if (!is_array($publicitySettings))
		{
			return false;
		}

		$isPublic = (bool)($publicitySettings[$region] ?? false);
		if (!$isPublic)
		{
			return false;
		}

		return
			Option::get('sign', 'SIGN_SEND_DOCUMENT_BY_EMPLOYEE_ENABLED', false)
			&& Storage::instance()->isB2eAvailable()
		;
	}

	private function read(string $name): mixed
	{
		$value = Main\Config\Configuration::getValue('sign')[$name] ?? null;
		if ($value !== null)
		{
			return $value;
		}

		return Main\Config\Configuration::getInstance('sign')->get($name);
	}

}