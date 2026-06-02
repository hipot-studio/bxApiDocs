<?php

namespace Bitrix\Socialnetwork\Controller\User;

use Bitrix\Socialnetwork\Controller\Base;

class StressLevel extends Base
{

	public function addAction(array $fields = []): array
	{
		return [
			'success' => false,
		];
	}

	public function getAction(array $fields = [])
	{
		return [];
	}

	public function getAccessAction(array $fields = []): ?array
	{
		return [
			'value' => 'N',
		];
	}

	public function setAccessAction(array $fields = []): ?array
	{
		return [
			'value' => false,
		];
	}

	public function getValueDescriptionAction($type = '', $value = false): ?array
	{
		return [
			'description' => '',
		];
	}

	public function setDisclaimerAction()
	{
		return [];
	}

	public function getDisclaimerAction()
	{
		return [];
	}
}
