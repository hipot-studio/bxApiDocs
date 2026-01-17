<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use CTaskFiles;

class DeleteLegacyFiles
{
	public function __invoke(array $template): void
	{
		if (empty($template['FILES']))
		{
			return;
		}

		$files = unserialize($template["FILES"], ['allowed_classes' => false]);
		if (is_array($files))
		{
			$filesToDelete = [];
			foreach ($files as $file)
			{
				$rsFile = CTaskFiles::GetList([], ["FILE_ID" => $file]);
				if (!$arFile = $rsFile->Fetch())
				{
					$filesToDelete[] = $file;
				}
			}
			foreach ($filesToDelete as $file)
			{
				\CFile::Delete($file);
			}
		}
	}
}
