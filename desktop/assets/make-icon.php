<?php

// Draws the Peep icon (the viewer's favicon: a purple disc with a white pupil) as a
// 1024 px PNG, drawn at 4x and scaled down for smooth edges. Run: php assets/make-icon.php
// Then: bun run tauri icon assets/icon.png

ini_set('memory_limit', '1G');

$size = 1024;
$scale = 4;
$big = imagecreatetruecolor($size * $scale, $size * $scale);
imagesavealpha($big, true);
imagefill($big, 0, 0, imagecolorallocatealpha($big, 0, 0, 0, 127));

$center = (int) ($size * $scale / 2);
imagefilledellipse($big, $center, $center, (int) ($size * $scale * 0.875), (int) ($size * $scale * 0.875), imagecolorallocate($big, 0x5b, 0x5b, 0xd6));
imagefilledellipse($big, $center, $center, (int) ($size * $scale * 0.375), (int) ($size * $scale * 0.375), imagecolorallocate($big, 0xff, 0xff, 0xff));

$icon = imagecreatetruecolor($size, $size);
imagesavealpha($icon, true);
imagealphablending($icon, false);
imagefill($icon, 0, 0, imagecolorallocatealpha($icon, 0, 0, 0, 127));
imagecopyresampled($icon, $big, 0, 0, 0, 0, $size, $size, $size * $scale, $size * $scale);
imagepng($icon, __DIR__ . '/icon.png');

// The tray icon: a white template image (macOS tints it for light and dark menu bars).
$tray = imagecreatetruecolor(64 * $scale, 64 * $scale);
imagesavealpha($tray, true);
imagealphablending($tray, false);
imagefill($tray, 0, 0, imagecolorallocatealpha($tray, 0, 0, 0, 127));
imagealphablending($tray, true);
$white = imagecolorallocate($tray, 0, 0, 0);
$c = 32 * $scale;
imagesetthickness($tray, 7 * $scale);
imagearc($tray, $c, $c, 52 * $scale, 52 * $scale, 0, 360, $white);
imagefilledellipse($tray, $c, $c, 22 * $scale, 22 * $scale, $white);
$small = imagecreatetruecolor(64, 64);
imagesavealpha($small, true);
imagealphablending($small, false);
imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
imagecopyresampled($small, $tray, 0, 0, 0, 0, 64, 64, 64 * $scale, 64 * $scale);
imagepng($small, __DIR__ . '/../src-tauri/icons/tray.png');
