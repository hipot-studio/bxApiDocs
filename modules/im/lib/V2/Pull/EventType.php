<?php

namespace Bitrix\Im\V2\Pull;

enum EventType: string
{
	case StartWriting = 'startWriting';
	case InputActionNotify = 'inputActionNotify';
}
