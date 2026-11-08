<?php

namespace Bitrix\BIConnector\DB;

interface BiTimeLimitInterface
{
	public function setBiTimeLimitSeconds(int $seconds): void;
}
