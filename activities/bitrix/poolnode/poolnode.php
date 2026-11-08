<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$runtime = CBPRuntime::GetRuntime();
$runtime->IncludeActivityFile('SequenceActivity');

class CBPPoolNode extends CBPSequenceActivity
{
	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
		];
	}
}
