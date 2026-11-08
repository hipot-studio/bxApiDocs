<?php

namespace Bitrix\Main\Cli\Output;

enum OutputFormat: string
{
	case Table = 'table';
	case Json = 'json';
}
