<?php

namespace Bitrix\Socialservices\OAuth;

enum OAuthErrorCode
{
	case MissingCode;
	case InvalidCheckKey;
	case Unknown;
}
