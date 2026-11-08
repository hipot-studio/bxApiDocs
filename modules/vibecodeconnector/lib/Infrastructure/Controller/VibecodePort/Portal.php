<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller\VibecodePort;

use Bitrix\Main\Engine\Response;
use Bitrix\Main\Request;
use Bitrix\Vibecodeconnector\Public\Provider\PortalAdminListProvider;

class Portal extends Base
{
	private PortalAdminListProvider $provider;

	public function __construct(?Request $request = null, ?PortalAdminListProvider $provider = null)
	{
		$this->provider = $provider ?? new PortalAdminListProvider();
		parent::__construct($request);
	}

	public function adminListAction(): Response\AjaxJson
	{
		return Response\AjaxJson::createSuccess($this->provider->get());
	}
}
