<?php
namespace Bitrix\Imconnector\Update;

use Bitrix\ImConnector;

/**
 * Class UpdateVersion
 *
 * @package Bitrix\Imconnector\Update
 * @internal
 */
class UpdateVersion
{
	/**
	 * @return string
	 */
	public static function updateForServer(): string
	{
		ImConnector\Output::saveDomainSite(ImConnector\Connector::getDomainDefault());

		return '';
	}
}