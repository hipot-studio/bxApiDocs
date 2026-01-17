<?php

namespace Bitrix\Main\Cli\Command\Make\Templates\Module;

use Bitrix\Main\Cli\Helper\Renderer\Template;

final class InstallTemplate implements Template
{
	public function __construct(
		private readonly string $moduleId,
		private readonly string $moduleIdNormalized,
		private readonly string $phrasePrefix,
	)
	{}

	public function getContent(): string
	{
		return <<<PHP
<?php

use Bitrix\Main\EventManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

class {$this->moduleIdNormalized} extends \CModule
{
	public function __construct()
	{
		\$this->MODULE_ID = '{$this->moduleId}';
		\$this->MODULE_NAME = Loc::getMessage('{$this->phrasePrefix}_NAME');
		\$this->MODULE_DESCRIPTION = Loc::getMessage('{$this->phrasePrefix}_DESCRIPTION');

		require __DIR__ . '/version.php';

		if (isset(\$arModuleVersion['VERSION']))
		{
			\$this->MODULE_VERSION = \$arModuleVersion['VERSION'];
		}

		if (isset(\$arModuleVersion['VERSION_DATE']))
		{
			\$this->MODULE_VERSION_DATE = \$arModuleVersion['VERSION_DATE'];
		}
	}

	public function DoInstall()
	{
		global \$USER;

		/**
		 * @var \CUser \$USER
		 */

		if (!\$USER->IsAdmin())
		{
			return;
		}

		ModuleManager::registerModule(\$this->MODULE_ID);

		\$this->InstallDB();
		\$this->InstallFiles();
		\$this->InstallEvents();
		\$this->InstallTasks();
	}

	public function DoUninstall()
	{
		global \$USER;

		/**
		 * @var \CUser \$USER
		 */

		if (!\$USER->IsAdmin())
		{
			return;
		}

		\$this->UnInstallDB();
		\$this->UnInstallTasks();
		\$this->UnInstallEvents();
		\$this->UnInstallFiles();

		ModuleManager::unRegisterModule(\$this->MODULE_ID);
	}

	public function InstallDB()
	{
		// установка событий
		// EventManager::getInstance()->registerEventHandler(...);

		// установка агентов
		// CAgent::AddAgent(...);
	}

	public function InstallEvents()
	{
		// установка почтовых и СМС шаблонов
		// \$type = new CEventType;
		// \$type->Add(...);
	}

	public function InstallFiles()
	{
		// установка файлов
		// CopyDirFiles(\$_SERVER['DOCUMENT_ROOT'] . "/local/modules/{\$this->MODULE_ID}/install/admin", \$_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin');
	}
}
PHP;
	}
}
