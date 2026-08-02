<?php

namespace Bitrix\Tasks\Integration\Pull;

abstract class PushParam
{
	// Top-level boolean marker: change initiated by the AI assistant on behalf of the
	// current user. Shared key across task pull paths (single source of truth).
	public const FROM_AI = 'FROM_AI';
}
