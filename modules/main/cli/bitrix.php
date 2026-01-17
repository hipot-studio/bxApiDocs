<?php
/**
 * Bitrix Framework
 * @package    bitrix
 * @subpackage main
 * @copyright  2001-2018 Bitrix
 */

/**
 * executable file example (project/bitrix/bitrix):
 * #!/usr/bin/php
 * <?php
 * $_SERVER["DOCUMENT_ROOT"] = realpath(__DIR__.'/../');
 * require_once(__DIR__.'/modules/main/dev/cli/bitrix.php');
 */

// include bitrix
require_once 'bootstrap.php';

// initialize symfony
use Symfony\Component\Console\Application;

if (class_exists(Application::class) === false)
{
	die(<<<TXT

	Symfony Console is not installed.
	Please install and configure composer in your project.
	For details see official documentation https://docs.1c-bitrix.ru/pages/get-started/composer.html


	TXT);
}

$application = new Application();

// register  commands
$modules = \Bitrix\Main\ModuleManager::getInstalledModules();
foreach ($modules as $moduleId => $_)
{
	$config = \Bitrix\Main\Config\Configuration::getInstance($moduleId)->get('console');
	if (isset($config['commands']) && is_array($config['commands']))
	{
		if (\Bitrix\Main\Loader::includeModule($moduleId))
		{
			foreach ($config['commands'] as $commandClass)
			{
				if (is_a($commandClass, \Symfony\Component\Console\Command\Command::class, true))
				{
					$application->add(new $commandClass());
				}
			}
		}
	}
}

// run console
$application->run();
