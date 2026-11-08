<?php

namespace Bitrix\Sign\Config;

use Bitrix\Main\Config\Option;
use Bitrix\Main;

final class Feature
{
	public const SIGN_COLLAB_INTEGRATION_ENABLED_OPTION = 'SIGN_COLLAB_INTEGRATION_ENABLED';
	public const SIGN_B2E_ROBOT_ENABLED_OPTION = 'SIGN_B2E_ROBOT_ENABLED_OPTION';
	public const SIGN_B2E_BIZPROC_CHAIN_ENABLED_OPTION = 'SIGN_B2E_BIZPROC_CHAIN_ENABLED';
	public const SIGN_DOCUMENT_MESSAGE_ENABLED_OPTION = 'sign_document_message_enabled';
	public const SIGN_KEDO_RUN_PROCESS_ENABLED_OPTION = 'SIGN_KEDO_RUN_PROCESS_ENABLED_OPTION';
	public const SIGN_KEDO_BIZPROC_FLAGS_MIGRATED_TO_MAIN_OPTION = 'SIGN_KEDO_BIZPROC_FLAGS_MIGRATED_TO_MAIN';
	private static ?self $instance = null;

	public static function instance(): self
	{
		if (!self::$instance)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function isDocumentTemplatesAvailable(): bool
	{
		return $this->isSendDocumentByEmployeeEnabled() || $this->isB2eRobotEnabled();
	}

	public function isSendDocumentByEmployeeEnabled(?string $region = null): bool
	{
		$region ??= Main\Application::getInstance()->getLicense()->getRegion();

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

		return Storage::instance()->isB2eAvailable();
	}

	public function isCollabIntegrationEnabled(): bool
	{
		return
			Option::get('sign', self::SIGN_COLLAB_INTEGRATION_ENABLED_OPTION, true)
			&& Storage::instance()->isAvailable();
	}

	public function isB2eRobotEnabled(?string $region = null): bool
	{
		$region ??= Main\Application::getInstance()->getLicense()->getRegion();

		$publicitySettings = $this->read('service.b2e.robot.publicity');
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
			Option::get('sign', self::SIGN_B2E_ROBOT_ENABLED_OPTION, true)
			&& Storage::instance()->isAvailable();
	}

	public function isB2eBizprocChainEnabled(?string $region = null, ?int $userId = null): bool
	{
		$region ??= Main\Application::getInstance()->getLicense()->getRegion();

		if (!$this->areKedoBizprocFlagsMigratedToMain())
		{
			return $this->isB2eBizprocChainEnabledByLegacyState($region);
		}

		if (!Storage::instance()->isB2eAvailable($region))
		{
			return false;
		}

		if (!$this->isB2eRobotEnabled($region))
		{
			return false;
		}

		return $this->isMainFeatureEnabled(Feature\B2eBizprocChainFlag::class, $region, $userId);
	}

	private function isB2eBizprocChainEnabledByLegacyState(?string $region): bool
	{
		if (!Storage::instance()->isB2eAvailable($region))
		{
			return false;
		}

		if (!$this->isB2eRobotEnabled($region))
		{
			return false;
		}

		$publicitySettings = $this->read('service.b2e.bizproc-chain.publicity');
		$isPublic = is_array($publicitySettings) && ($publicitySettings[$region] ?? false);

		return $this->isOptionEnabled(self::SIGN_B2E_BIZPROC_CHAIN_ENABLED_OPTION, $isPublic);
	}

	/**
	 * Whether a switch of the module is on. enableOption() and disableOption() store the flag as
	 * '1' and as an empty string, while a portal switching the option by hand writes the 'N' of
	 * the platform: the values that mean off are named, instead of asking the string for its
	 * truthiness and reading 'N' as on.
	 *
	 * Only the bizproc chain and KEDO process flags are read this way: the helper came with those flags.
	 * Reading
	 * isCollabIntegrationEnabled(), isB2eRobotEnabled() or isDocumentMessageEnabled() through
	 * it would change how those features behave, an option set to 'N' by hand starting to
	 * switch them off, so the move needs their owners to agree and a task of its own. The
	 * methods comparing the option to 'Y' need no move: a hand-written 'N' switches those off
	 * already.
	 */
	private function isOptionEnabled(string $name, bool $default): bool
	{
		$value = Option::get('sign', $name, $default);

		return !in_array((string)$value, ['', '0', 'N'], true);
	}

	public function isDocumentMessageEnabled(?string $region = null): bool
	{
		$region ??= Main\Application::getInstance()->getLicense()->getRegion();

		$publicitySettings = $this->read('service.b2e.document-message.publicity');
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
			Option::get('sign', self::SIGN_DOCUMENT_MESSAGE_ENABLED_OPTION, true)
			&& Storage::instance()->isB2eAvailable($region)
		;
	}

	public function isKedoRunProcessEnabled(?string $region = null): bool
	{
		$region ??= Main\Application::getInstance()->getLicense()->getRegion();

		if (!$this->areKedoBizprocFlagsMigratedToMain())
		{
			return $this->isKedoRunProcessEnabledByLegacyState($region);
		}

		$publicitySettings = $this->read('service.b2e.run-process.publicity');
		if (!is_array($publicitySettings))
		{
			return false;
		}

		$isPublic = (bool)($publicitySettings[$region] ?? false);
		if (!$isPublic)
		{
			return false;
		}

		if (!$this->isMainFeatureEnabled(Feature\KedoRunProcessFlag::class, $region))
		{
			return false;
		}

		return Storage::instance()->isB2eAvailable($region);
	}

	private function isKedoRunProcessEnabledByLegacyState(?string $region): bool
	{
		$publicitySettings = $this->read('service.b2e.run-process.publicity');
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
			$this->isOptionEnabled(self::SIGN_KEDO_RUN_PROCESS_ENABLED_OPTION, true)
			&& Storage::instance()->isB2eAvailable($region)
		;
	}

	private function areKedoBizprocFlagsMigratedToMain(): bool
	{
		return Option::getRealValue(
			'sign',
			self::SIGN_KEDO_BIZPROC_FLAGS_MIGRATED_TO_MAIN_OPTION,
			'',
		) === 'Y';
	}

	private function isMainFeatureEnabled(string $flag, ?string $region, ?int $userId = null): bool
	{
		try
		{
			if (
				!class_exists(Main\Config\Feature::class)
				|| !class_exists(Main\Config\Feature\AbstractFlag::class)
				|| !class_exists(Main\Config\Feature\Context::class)
				|| !class_exists($flag)
				|| !is_subclass_of($flag, Main\Config\Feature\AbstractFlag::class)
			)
			{
				return false;
			}

			$currentContext = Main\Config\Feature\Context::getCurrent();
			$context = new Main\Config\Feature\Context(
				$userId ?? $currentContext->userId,
				['region' => $region],
			);

			return Main\Config\Feature::isEnabled($flag, $context);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function enableOption(string $name): void
	{
		Option::set('sign', $name, true);
	}

	public function disableOption(string $name): void
	{
		Option::set('sign', $name, false);
	}

	public function isSenderTypeAvailable(): bool
	{
		return $this->isB2eRobotEnabled() && $this->isSendDocumentByEmployeeEnabled();
	}

	public function isMultiDocumentLoadingEnabled(): bool
	{
		return Option::get("sign", "is_multi_document_loading_enabled", 'Y') === 'Y';
	}

	public function isGroupSendingEnabled(): bool
	{
		return Option::get("sign", "is_group_sending_enabled", 'Y') === 'Y';
	}

	public function isDocumentsInSignersSelectorEnabled(): bool
	{
		return Option::get("sign", "is_documents_in_signers_selector_enabled", 'N') === 'Y';
	}

	public function isTemplateFolderGroupingAllowed(): bool
	{
		return Option::get('sign', 'TEMPLATE_FOLDER_GROUPING_ALLOWED', 'Y') === 'Y';
	}

	public function isSafeFolderGroupingAllowed(): bool
	{
		return Option::get('sign', 'SAFE_FOLDER_GROUPING_ALLOWED', 'Y') === 'Y';
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
