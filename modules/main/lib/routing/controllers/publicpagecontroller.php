<?php
/**
 * Bitrix Framework
 * @package    bitrix
 * @subpackage main
 * @copyright  2001-2020 Bitrix
 */

namespace Bitrix\Main\Routing\Controllers;

use Bitrix\Main\Routing\Route;

/**
 * @package    bitrix
 * @subpackage main
 */
class PublicPageController
{
	protected $path;

	public function __construct($path)
	{
		$this->path = $path;
	}

	public function __invoke(Route $route)
	{
		include_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/classes/general/virtual_io.php';
		$io = \CBXVirtualIo::GetInstance();

		$_SERVER['REAL_FILE_PATH'] = $this->getPath();

		include_once $io->GetPhysicalName($_SERVER['DOCUMENT_ROOT'] . $this->getPath());

		die();
	}

	/**
	 * @return mixed
	 */
	public function getPath()
	{
		return $this->path;
	}
}
