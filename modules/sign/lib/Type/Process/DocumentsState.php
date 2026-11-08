<?php

namespace Bitrix\Sign\Type\Process;

/**
 * State of the documents cell of a row. None and NotCreated are both an empty list of documents and are
 * told apart by the process alone: while it runs the documents may still appear, once it is over they
 * never will.
 */
enum DocumentsState: string
{
	case None = 'none';
	case Created = 'created';
	case NotCreated = 'notCreated';
}
