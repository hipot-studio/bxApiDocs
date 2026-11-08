<?php

namespace Bitrix\BizprocDesigner\Internal\Entity;

enum NodeType: string
{
	case Simple = 'simple';
	case Complex = 'complex';
	case Trigger = 'trigger';
	case Frame = 'frame';
	case Operators = 'operators';
	case Services = 'services';
	case Tool = 'tool';
}
