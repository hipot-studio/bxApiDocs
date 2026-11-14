<?php

declare(strict_types=1);

namespace Bitrix\Call\Integration\Main\EventHandler;

use Bitrix\Main\EventResult;
use Bitrix\Main\UI\Copyright;

/**
 * The third-party components of the call module for the registry page /bitrix/admin/copyright.php.
 *
 * One row per item of the summary of install/js/call/THIRD_PARTY_NOTICES.txt, the values are copied verbatim
 * from the registry block of the item; OnGetThirdPartySoftwareTest keeps the two in step.
 */
final class OnGetThirdPartySoftware
{
	private const NOTICES_URL = '/bitrix/js/call/THIRD_PARTY_NOTICES.txt';

	public static function handle(): EventResult
	{
		return new EventResult(EventResult::SUCCESS, self::getComponents(), 'call');
	}

	/**
	 * @return Copyright[]
	 */
	private static function getComponents(): array
	{
		return [
			(new Copyright('MediaPipe (@mediapipe/tasks-vision 1.0.1)'))
				->setProductUrl('https://github.com/google-ai-edge/mediapipe')
				->setCopyright('Copyright 2022, 2023 The MediaPipe Authors. Copyright 2019 The MediaPipe Authors. Copyright Google LLC. Copyright The Closure Library Authors.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_APACHE2)
				->setLicenceUrl('https://raw.githubusercontent.com/google-ai-edge/mediapipe/master/LICENSE'),

			(new Copyright('MediaPipe ml_drift, gloop, odml'))
				->setProductUrl('https://github.com/google-ai-edge/mediapipe')
				->setCopyright('Copyright 2022, 2023 The MediaPipe Authors. Copyright Google LLC.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_APACHE2)
				->setLicenceUrl('https://raw.githubusercontent.com/google-ai-edge/mediapipe/master/LICENSE'),

			(new Copyright('MediaPipe Selfie Segmentation model'))
				->setProductUrl('https://ai.google.dev/edge/mediapipe/solutions/vision/image_segmenter')
				->setCopyright('Google (per Model Card MediaPipe Selfie Segmentation)')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_APACHE2)
				->setLicenceUrl('https://www.apache.org/licenses/LICENSE-2.0'),

			(new Copyright('TensorFlow Lite'))
				->setProductUrl('https://github.com/tensorflow/tensorflow')
				->setCopyright('Copyright 2020 The TensorFlow Authors. All Rights Reserved. Copyright (c) 2014, The Regents of the University of California (Regents). Copyright (c) 2014, the respective contributors.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_APACHE2)
				->setLicenceUrl('https://raw.githubusercontent.com/tensorflow/tensorflow/master/LICENSE'),

			(new Copyright('Abseil'))
				->setProductUrl('https://github.com/abseil/abseil-cpp')
				->setCopyright('Copyright 2017 The Abseil Authors.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_APACHE2)
				->setLicenceUrl('https://raw.githubusercontent.com/abseil/abseil-cpp/master/LICENSE'),

			(new Copyright('OpenCV'))
				->setProductUrl('https://github.com/opencv/opencv')
				->setCopyright('Copyright (C) 2000-2022, Intel Corporation, all rights reserved. Copyright (C) 2009-2011, Willow Garage Inc., all rights reserved. Copyright (C) 2009-2016, NVIDIA Corporation, all rights reserved. Copyright (C) 2010-2013, Advanced Micro Devices, Inc., all rights reserved. Copyright (C) 2015-2023, OpenCV Foundation, all rights reserved. Copyright (C) 2008-2016, Itseez Inc., all rights reserved. Copyright (C) 2019-2023, Xperience AI, all rights reserved. Copyright (C) 2019-2022, Shenzhen Institute of Artificial Intelligence and Robotics for Society, all rights reserved. Copyright (C) 2022-2023, Southern University of Science And Technology, all rights reserved. Copyright (C) 2023-2025, OpenCV AI, all rights reserved. Copyright (C) 2024, Bigvision LLC, all rights reserved. Third party copyrights are property of their respective owners.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_APACHE2)
				->setLicenceUrl('https://raw.githubusercontent.com/opencv/opencv/4.13.0/LICENSE'),

			(new Copyright('ruy'))
				->setProductUrl('https://github.com/google/ruy')
				->setCopyright('Copyright 2019 Google LLC. All Rights Reserved.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_APACHE2)
				->setLicenceUrl('https://raw.githubusercontent.com/google/ruy/master/LICENSE'),

			(new Copyright('gemmlowp'))
				->setProductUrl('https://github.com/google/gemmlowp')
				->setCopyright('Copyright 2015 The Gemmlowp Authors. All Rights Reserved.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_APACHE2)
				->setLicenceUrl('https://raw.githubusercontent.com/google/gemmlowp/master/LICENSE'),

			(new Copyright('FlatBuffers'))
				->setProductUrl('https://github.com/google/flatbuffers')
				->setCopyright('Copyright 2014 Google Inc. All rights reserved.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_APACHE2)
				->setLicenceUrl('https://raw.githubusercontent.com/google/flatbuffers/master/LICENSE'),

			(new Copyright('SentencePiece'))
				->setProductUrl('https://github.com/google/sentencepiece')
				->setCopyright('Copyright 2016 Google Inc.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_APACHE2)
				->setLicenceUrl('https://raw.githubusercontent.com/google/sentencepiece/master/LICENSE'),

			(new Copyright('TCMalloc'))
				->setProductUrl('https://github.com/google/tcmalloc')
				->setCopyright('Copyright 2019 The TCMalloc Authors.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_APACHE2)
				->setLicenceUrl('https://raw.githubusercontent.com/google/tcmalloc/master/LICENSE'),

			(new Copyright('libc++, libc++abi, compiler-rt (LLVM)'))
				->setProductUrl('https://github.com/llvm/llvm-project')
				->setCopyright('Copyright (c) 2009-2019 by the contributors listed in CREDITS.TXT. Copyright (c) 2009-2014 by the contributors listed in CREDITS.TXT. Copyright (c) 2009-2015 by the contributors listed in CREDITS.TXT.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence('Apache License 2.0 with LLVM Exceptions')
				->setLicenceUrl('https://raw.githubusercontent.com/emscripten-core/emscripten/main/system/lib/libcxxabi/LICENSE.TXT'),

			(new Copyright('XNNPACK'))
				->setProductUrl('https://github.com/google/XNNPACK')
				->setCopyright('Copyright (c) Facebook, Inc. and its affiliates. All rights reserved. Copyright 2019 Google LLC.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_BSD3)
				->setLicenceUrl('https://raw.githubusercontent.com/google/XNNPACK/master/LICENSE'),

			(new Copyright('Protocol Buffers'))
				->setProductUrl('https://github.com/protocolbuffers/protobuf')
				->setCopyright('Copyright 2008 Google Inc.  All rights reserved.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_BSD3)
				->setLicenceUrl('https://raw.githubusercontent.com/protocolbuffers/protobuf/main/LICENSE'),

			(new Copyright('Eigen'))
				->setProductUrl('https://gitlab.com/libeigen/eigen')
				->setCopyright('Eigen authors (per individual file headers)')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence('Mozilla Public License 2.0')
				->setLicenceUrl('https://gitlab.com/libeigen/eigen/-/raw/ea13a98decd497a8c5588fb5de71b57bcf10d864/COPYING.MPL2'),

			// The registry page shows licenceText only when there is no licence URL.
			(new Copyright('fft2d (General Purpose FFT Package)'))
				->setProductUrl('https://github.com/petewarden/OouraFFT')
				->setCopyright('Copyright(C) 1997,2001 Takuya OOURA (email: ooura@kurims.kyoto-u.ac.jp). Copyright(C) 1996-2001 Takuya OOURA.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence('fft2d license (Takuya Ooura)')
				->setLicenceText(implode("\n", [
					'You may use, copy, modify this code for any purpose and ',
					'without fee. You may distribute this ORIGINAL package.',
				])),

			(new Copyright('Emscripten'))
				->setProductUrl('https://github.com/emscripten-core/emscripten')
				->setCopyright('Copyright (c) 2010-2014 Emscripten authors, see AUTHORS file. Copyright Joyent, Inc. and other Node contributors. All rights reserved.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence('MIT or University of Illinois/NCSA Open Source License')
				->setLicenceUrl('https://raw.githubusercontent.com/emscripten-core/emscripten/main/LICENSE'),

			(new Copyright('musl libc'))
				->setProductUrl('https://musl.libc.org/')
				->setCopyright('Copyright © 2005-2020 Rich Felker, et al.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_MIT)
				->setLicenceUrl('https://raw.githubusercontent.com/emscripten-core/emscripten/3.1.14/system/lib/libc/musl/COPYRIGHT'),

			(new Copyright('RNNoise'))
				->setProductUrl('https://github.com/xiph/rnnoise')
				->setCopyright('Copyright (c) 2007-2017, 2024 Jean-Marc Valin. Copyright (c) 2023 Amazon. Copyright (c) 2017, Mozilla. Copyright (c) 2005-2017, Xiph.Org Foundation. Copyright (c) 2003-2004, Mark Borgerding.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_BSD3)
				->setLicenceUrl('https://raw.githubusercontent.com/xiph/rnnoise/v0.2/COPYING'),

			(new Copyright('Jitsi rnnoise-wasm 0.2.1'))
				->setProductUrl('https://github.com/jitsi/rnnoise-wasm')
				->setCopyright('Jitsi project contributors (8x8, Inc.). Copyright (c) 2013 ESTOS GmbH. Copyright (c) 2013 BlueJimp SARL.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_APACHE2)
				->setLicenceUrl('https://raw.githubusercontent.com/jitsi/rnnoise-wasm/master/LICENSE'),

			(new Copyright('Jitsi Meet (noise suppression code)'))
				->setProductUrl('https://github.com/jitsi/jitsi-meet')
				->setCopyright('Jitsi project contributors (8x8, Inc.). Copyright (c) 2013 ESTOS GmbH. Copyright (c) 2013 BlueJimp SARL.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_APACHE2)
				->setLicenceUrl('https://raw.githubusercontent.com/jitsi/jitsi-meet/master/LICENSE'),

			(new Copyright('pthreadpool'))
				->setProductUrl('https://github.com/google/pthreadpool')
				->setCopyright('Copyright 2019 Google LLC. Copyright (c) 2017 Facebook Inc. Copyright (c) 2015-2017 Georgia Institute of Technology.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_BSD2)
				->setLicenceUrl('https://raw.githubusercontent.com/google/pthreadpool/main/LICENSE'),

			(new Copyright('FP16'))
				->setProductUrl('https://github.com/Maratyszcza/FP16')
				->setCopyright('Copyright (c) 2017 Facebook Inc. Copyright (c) 2017 Georgia Institute of Technology. Copyright 2019 Google LLC.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_MIT)
				->setLicenceUrl('https://raw.githubusercontent.com/Maratyszcza/FP16/master/LICENSE'),

			(new Copyright('utf8_range'))
				->setProductUrl('https://github.com/protocolbuffers/protobuf/tree/main/third_party/utf8_range')
				->setCopyright('Copyright (c) 2019 Yibo Cai. Copyright 2022 Google LLC.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_MIT)
				->setLicenceUrl('https://raw.githubusercontent.com/protocolbuffers/protobuf/v31.1/third_party/utf8_range/LICENSE'),

			(new Copyright('farmhash'))
				->setProductUrl('https://github.com/google/farmhash')
				->setCopyright('Copyright (c) 2014 Google, Inc.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_MIT)
				->setLicenceUrl('https://raw.githubusercontent.com/google/farmhash/0d859a811870d10f53a594927d0d0b97573ad06d/COPYING'),

			(new Copyright('cpuinfo'))
				->setProductUrl('https://github.com/pytorch/cpuinfo')
				->setCopyright('Copyright (c) 2019 Google LLC. Copyright (c) 2017-2018 Facebook Inc. Copyright (C) 2012-2017 Georgia Institute of Technology. Copyright (C) 2010-2012 Marat Dukhan.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_BSD2)
				->setLicenceUrl('https://raw.githubusercontent.com/pytorch/cpuinfo/ea6b9f1bb6e1001d8b21574d5bc78ddef62e499d/LICENSE'),

			(new Copyright('FXdiv'))
				->setProductUrl('https://github.com/Maratyszcza/FXdiv')
				->setCopyright('Copyright (c) 2017 Facebook Inc. Copyright (c) 2016-2017 Marat Dukhan.')
				->setCopyrightUrl(self::NOTICES_URL)
				->setLicence(Copyright::LICENCE_MIT)
				->setLicenceUrl('https://raw.githubusercontent.com/Maratyszcza/FXdiv/63058eff77e11aa15bf531df5dd34395ec3017c8/LICENSE'),
		];
	}
}
