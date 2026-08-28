<?php
declare(strict_types=1);

function pdf_ascii_text(string $value): string
{
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = str_replace(['—', '–'], '-', $value);
    $value = preg_replace('/[^\x20-\x7E]/', ' ', $value) ?? '';
    return trim((string) preg_replace('/\s+/', ' ', $value));
}

function pdf_literal_string(string $value): string
{
    return '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], pdf_ascii_text($value)) . ')';
}

function pdf_find_dictionary_end(string $pdf, int $start): ?int
{
    $length = strlen($pdf);
    $depth = 0;
    for ($i = $start; $i < $length - 1; $i++) {
        $pair = $pdf[$i] . $pdf[$i + 1];
        if ($pair === '<<') {
            $depth++;
            $i++;
            continue;
        }
        if ($pair === '>>') {
            $depth--;
            $i++;
            if ($depth === 0) return $i + 1;
        }
    }
    return null;
}

function pdf_object_dictionary(string $pdf, int $objectNumber): ?array
{
    if (!preg_match('/\b' . preg_quote((string) $objectNumber, '/') . '\s+0\s+obj\s*<</', $pdf, $match, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $dictStart = strpos($pdf, '<<', $match[0][1]);
    if ($dictStart === false) return null;
    $dictEnd = pdf_find_dictionary_end($pdf, $dictStart);
    if ($dictEnd === null) return null;
    return [
        'object_start' => $match[0][1],
        'dict_start' => $dictStart,
        'dict_end' => $dictEnd,
        'dict' => substr($pdf, $dictStart, $dictEnd - $dictStart + 1),
    ];
}

function pdf_merge_dictionary_entry(string $dict, string $entryName, string $entryLine): string
{
    if (preg_match('/\/' . preg_quote($entryName, '/') . '\s*<<(.*?)>>/s', $dict, $match, PREG_OFFSET_CAPTURE)) {
        $insertAt = $match[0][1] + strlen($match[0][0]) - 2;
        return substr($dict, 0, $insertAt) . ' ' . $entryLine . ' ' . substr($dict, $insertAt);
    }
    $insertAt = strrpos($dict, '>>');
    if ($insertAt === false) return $dict;
    return substr($dict, 0, $insertAt) . ' /' . $entryName . ' << ' . $entryLine . ' >> ' . substr($dict, $insertAt);
}

function pdf_append_to_dictionary(string $dict, string $entryLine): string
{
    $insertAt = strrpos($dict, '>>');
    if ($insertAt === false) return $dict;
    return substr($dict, 0, $insertAt) . ' ' . $entryLine . ' ' . substr($dict, $insertAt);
}

function pdf_merge_resource_xobject(string $pdf, string $resourceDict, string $xobjectLine, array &$objects): string
{
    if (preg_match('/\/XObject\s+(\d+)\s+0\s+R/', $resourceDict, $xref)) {
        $objectNumber = (int) $xref[1];
        $object = pdf_object_dictionary($pdf, $objectNumber);
        if ($object) {
            $objects[$objectNumber] = pdf_append_to_dictionary($object['dict'], $xobjectLine);
        }
        return $resourceDict;
    }
    return pdf_merge_dictionary_entry($resourceDict, 'XObject', $xobjectLine);
}

function pdf_update_resources(string $pdf, string $pageDict, string $xobjectLine, array &$objects): string
{
    if (preg_match('/\/Resources\s+(\d+)\s+0\s+R/', $pageDict, $match)) {
        $objectNumber = (int) $match[1];
        $object = pdf_object_dictionary($pdf, $objectNumber);
        if ($object) {
            $objects[$objectNumber] = pdf_merge_resource_xobject($pdf, $object['dict'], $xobjectLine, $objects);
        }
        return $pageDict;
    }

    if (preg_match('/\/Resources\s*(<<.*?>>)/s', $pageDict, $match, PREG_OFFSET_CAPTURE)) {
        $resourceStart = $match[1][1];
        $resourceEnd = pdf_find_dictionary_end($pageDict, $resourceStart);
        if ($resourceEnd !== null) {
            $resourceDict = substr($pageDict, $resourceStart, $resourceEnd - $resourceStart + 1);
            $resourceDict = pdf_merge_resource_xobject($pdf, $resourceDict, $xobjectLine, $objects);
            return substr($pageDict, 0, $resourceStart) . $resourceDict . substr($pageDict, $resourceEnd + 1);
        }
    }

    if (preg_match('/\/Parent\s+(\d+)\s+0\s+R/', $pageDict, $parentMatch)) {
        $parentNumber = (int) $parentMatch[1];
        for ($depth = 0; $depth < 10 && $parentNumber > 0; $depth++) {
            $parentObject = pdf_object_dictionary($pdf, $parentNumber);
            if (!$parentObject) break;
            $parentDict = $parentObject['dict'];
            if (preg_match('/\/Resources\s+(\d+)\s+0\s+R/', $parentDict, $resourceRef)) {
                $resourceObject = pdf_object_dictionary($pdf, (int) $resourceRef[1]);
                if ($resourceObject) {
                    $objects[(int) $resourceRef[1]] = pdf_merge_resource_xobject($pdf, $resourceObject['dict'], $xobjectLine, $objects);
                    return $pageDict;
                }
            }
            if (preg_match('/\/Resources\s*(<<.*?>>)/s', $parentDict, $resourceMatch, PREG_OFFSET_CAPTURE)) {
                $resourceStart = $resourceMatch[1][1];
                $resourceEnd = pdf_find_dictionary_end($parentDict, $resourceStart);
                if ($resourceEnd !== null) {
                    $resourceDict = substr($parentDict, $resourceStart, $resourceEnd - $resourceStart + 1);
                    $resourceDict = pdf_merge_resource_xobject($pdf, $resourceDict, $xobjectLine, $objects);
                    $objects[$parentNumber] = substr($parentDict, 0, $resourceStart) . $resourceDict . substr($parentDict, $resourceEnd + 1);
                    return $pageDict;
                }
            }
            if (!preg_match('/\/Parent\s+(\d+)\s+0\s+R/', $parentDict, $nextParent)) break;
            $parentNumber = (int) $nextParent[1];
        }
    }

    $insertAt = strrpos($pageDict, '>>');
    if ($insertAt === false) return $pageDict;
    return substr($pageDict, 0, $insertAt) . ' /Resources << /XObject << ' . $xobjectLine . ' >> >> ' . substr($pageDict, $insertAt);
}

function pdf_update_contents(string $pageDict, int $overlayObjectNumber): string
{
    $overlayRef = $overlayObjectNumber . ' 0 R';
    if (preg_match('/\/Contents\s*\[(.*?)\]/s', $pageDict, $match, PREG_OFFSET_CAPTURE)) {
        $insertAt = $match[0][1] + strlen($match[0][0]) - 1;
        return substr($pageDict, 0, $insertAt) . ' ' . $overlayRef . ' ' . substr($pageDict, $insertAt);
    }
    if (preg_match('/\/Contents\s+(\d+\s+0\s+R)/', $pageDict, $match, PREG_OFFSET_CAPTURE)) {
        return substr_replace($pageDict, '/Contents [' . $match[1][0] . ' ' . $overlayRef . ']', $match[0][1], strlen($match[0][0]));
    }
    $insertAt = strrpos($pageDict, '>>');
    if ($insertAt === false) return $pageDict;
    return substr($pageDict, 0, $insertAt) . ' /Contents ' . $overlayRef . ' ' . substr($pageDict, $insertAt);
}

function pdf_page_size(string $pageDict): array
{
    if (preg_match('/\/(?:CropBox|MediaBox)\s*\[\s*([^\]]+)\]/', $pageDict, $match)) {
        $numbers = preg_split('/\s+/', trim($match[1]));
        if (is_array($numbers) && count($numbers) >= 4) {
            $width = abs((float) $numbers[2] - (float) $numbers[0]);
            $height = abs((float) $numbers[3] - (float) $numbers[1]);
            if ($width > 0 && $height > 0) return [$width, $height];
        }
    }
    return [595.0, 842.0];
}

function pdf_page_box(string $pageDict): array
{
    if (preg_match('/\/(?:CropBox|MediaBox)\s*\[\s*([^\]]+)\]/', $pageDict, $match)) {
        $numbers = preg_split('/\s+/', trim($match[1]));
        if (is_array($numbers) && count($numbers) >= 4) {
            $x1 = (float) $numbers[0];
            $y1 = (float) $numbers[1];
            $x2 = (float) $numbers[2];
            $y2 = (float) $numbers[3];
            return [
                min($x1, $x2),
                min($y1, $y2),
                abs($x2 - $x1),
                abs($y2 - $y1),
            ];
        }
    }
    return [0.0, 0.0, 595.0, 842.0];
}

function pdf_startxref(string $pdf): ?int
{
    if (!preg_match_all('/startxref\s+(\d+)\s+%%EOF/s', $pdf, $matches)) return null;
    return (int) end($matches[1]);
}

function pdf_trailer_root(string $pdf): ?string
{
    if (!preg_match_all('/trailer\s*<<(.*?)>>\s*startxref/s', $pdf, $matches)) return null;
    $trailer = (string) end($matches[1]);
    return preg_match('/\/Root\s+(\d+\s+0\s+R)/', $trailer, $root) ? $root[1] : null;
}

function pdf_png_image_xobject(string $imagePath): ?array
{
    $png = is_file($imagePath) ? (string) file_get_contents($imagePath) : '';
    if ($png === '' || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;

    $offset = 8;
    $idat = '';
    $width = 0;
    $height = 0;
    $bitDepth = 0;
    $colorType = -1;
    $length = strlen($png);
    while ($offset + 8 <= $length) {
        $chunkLength = unpack('N', substr($png, $offset, 4))[1];
        $type = substr($png, $offset + 4, 4);
        $data = substr($png, $offset + 8, $chunkLength);
        if ($type === 'IHDR') {
            $header = unpack('Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace', $data);
            $width = (int) $header['width'];
            $height = (int) $header['height'];
            $bitDepth = (int) $header['bitDepth'];
            $colorType = (int) $header['colorType'];
            if (($header['compression'] ?? 1) !== 0 || ($header['filter'] ?? 1) !== 0 || ($header['interlace'] ?? 1) !== 0) {
                return null;
            }
        } elseif ($type === 'IDAT') {
            $idat .= $data;
        } elseif ($type === 'IEND') {
            break;
        }
        $offset += 12 + $chunkLength;
    }

    $colorMap = [
        0 => ['/DeviceGray', 1],
        2 => ['/DeviceRGB', 3],
    ];
    if ($width <= 0 || $height <= 0 || $bitDepth !== 8 || !isset($colorMap[$colorType]) || $idat === '') {
        return null;
    }

    [$colorSpace, $colors] = $colorMap[$colorType];
    if ($colorType === 2) {
        $converted = pdf_light_gray_png_streams($idat, $width, $height, 3);
        if ($converted) {
            return ['width' => $width, 'height' => $height, 'image_stream' => $converted['image'], 'mask_stream' => $converted['mask']];
        }
    }

    $dict = "<< /Type /XObject /Subtype /Image /Width {$width} /Height {$height} /ColorSpace {$colorSpace} /BitsPerComponent {$bitDepth} /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors {$colors} /BitsPerComponent {$bitDepth} /Columns {$width} >> /Length " . strlen($idat) . " >>\nstream\n{$idat}\nendstream\n";
    return ['width' => $width, 'height' => $height, 'object' => $dict];
}

function pdf_paeth_predictor(int $left, int $above, int $upperLeft): int
{
    $p = $left + $above - $upperLeft;
    $pa = abs($p - $left);
    $pb = abs($p - $above);
    $pc = abs($p - $upperLeft);
    if ($pa <= $pb && $pa <= $pc) return $left;
    return $pb <= $pc ? $above : $upperLeft;
}

function pdf_unfilter_png_scanlines(string $compressed, int $width, int $height, int $channels): ?string
{
    $raw = @gzuncompress($compressed);
    if (!is_string($raw)) return null;
    $rowLength = $width * $channels;
    $expected = ($rowLength + 1) * $height;
    if (strlen($raw) < $expected) return null;

    $output = '';
    $previous = array_fill(0, $rowLength, 0);
    $offset = 0;
    for ($row = 0; $row < $height; $row++) {
        $filter = ord($raw[$offset]);
        $offset++;
        $current = [];
        for ($i = 0; $i < $rowLength; $i++) {
            $value = ord($raw[$offset + $i]);
            $left = $i >= $channels ? $current[$i - $channels] : 0;
            $above = $previous[$i] ?? 0;
            $upperLeft = $i >= $channels ? ($previous[$i - $channels] ?? 0) : 0;
            $current[$i] = match ($filter) {
                0 => $value,
                1 => ($value + $left) & 0xff,
                2 => ($value + $above) & 0xff,
                3 => ($value + intdiv($left + $above, 2)) & 0xff,
                4 => ($value + pdf_paeth_predictor($left, $above, $upperLeft)) & 0xff,
                default => -1,
            };
            if ($current[$i] < 0) return null;
        }
        $output .= pack('C*', ...$current);
        $previous = $current;
        $offset += $rowLength;
    }
    return $output;
}

function pdf_light_gray_png_streams(string $idat, int $width, int $height, int $channels): ?array
{
    $pixels = pdf_unfilter_png_scanlines($idat, $width, $height, $channels);
    if ($pixels === null) return null;

    $image = '';
    $mask = '';
    $pixelCount = $width * $height;
    for ($row = 0; $row < $height; $row++) {
        $image .= "\x00";
        $mask .= "\x00";
        for ($col = 0; $col < $width; $col++) {
            $index = (($row * $width) + $col) * $channels;
            $r = ord($pixels[$index]);
            $g = ord($pixels[$index + 1]);
            $b = ord($pixels[$index + 2]);
            $brightness = (int) round(($r * 0.299) + ($g * 0.587) + ($b * 0.114));
            $alpha = max(0, min(255, (int) round((255 - $brightness) * 1.35)));
            if ($brightness > 246) {
                $alpha = 0;
            }
            $image .= chr(205);
            $mask .= chr($alpha);
        }
    }

    return [
        'image' => gzcompress($image, 9),
        'mask' => gzcompress($mask, 9),
    ];
}

function create_watermarked_pdf_copy(string $sourcePath, string $targetPath, string $watermarkText, ?string $watermarkImagePath = null): bool
{
    $pdf = (string) file_get_contents($sourcePath);
    if ($pdf === '' || !str_starts_with($pdf, '%PDF') || str_contains($pdf, '/Encrypt')) return false;

    preg_match_all('/\b(\d+)\s+0\s+obj\b/', $pdf, $objectMatches);
    $maxObject = $objectMatches[1] ? max(array_map('intval', $objectMatches[1])) : 0;
    $previousXref = pdf_startxref($pdf);
    $rootRef = pdf_trailer_root($pdf);
    if (!$previousXref || !$rootRef || $maxObject < 1) return false;

    preg_match_all('/\b(\d+)\s+0\s+obj\s*<</', $pdf, $matches, PREG_OFFSET_CAPTURE);
    $pages = [];
    foreach ($matches[1] as $index => $numberMatch) {
        $dictStart = strpos($pdf, '<<', $matches[0][$index][1]);
        if ($dictStart === false) continue;
        $dictEnd = pdf_find_dictionary_end($pdf, $dictStart);
        if ($dictEnd === null) continue;
        $dict = substr($pdf, $dictStart, $dictEnd - $dictStart + 1);
        if (preg_match('/\/Type\s*\/Page\b/', $dict) && !preg_match('/\/Type\s*\/Pages\b/', $dict)) {
            $pages[(int) $numberMatch[0]] = $dict;
        }
    }
    if (!$pages) return false;

    $graphicsStateObject = ++$maxObject;
    $objects = [
        $graphicsStateObject => "<< /Type /ExtGState /ca 0.32 /CA 0.32 >>\n",
    ];
    $fontObject = ++$maxObject;
    $objects[$fontObject] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\n";
    $imageObject = null;
    $imageInfo = $watermarkImagePath ? pdf_png_image_xobject($watermarkImagePath) : null;
    if ($imageInfo) {
        if (isset($imageInfo['image_stream'], $imageInfo['mask_stream'])) {
            $maskObject = ++$maxObject;
            $objects[$maskObject] = "<< /Type /XObject /Subtype /Image /Width {$imageInfo['width']} /Height {$imageInfo['height']} /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns {$imageInfo['width']} >> /Length " . strlen($imageInfo['mask_stream']) . " >>\nstream\n{$imageInfo['mask_stream']}\nendstream\n";
            $imageObject = ++$maxObject;
            $objects[$imageObject] = "<< /Type /XObject /Subtype /Image /Width {$imageInfo['width']} /Height {$imageInfo['height']} /ColorSpace /DeviceGray /BitsPerComponent 8 /SMask {$maskObject} 0 R /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns {$imageInfo['width']} >> /Length " . strlen($imageInfo['image_stream']) . " >>\nstream\n{$imageInfo['image_stream']}\nendstream\n";
        } else {
        $imageObject = ++$maxObject;
        $objects[$imageObject] = $imageInfo['object'];
        }
    }

    $watermarkText = pdf_ascii_text($watermarkText);
    foreach ($pages as $pageObject => $pageDict) {
        [$pageX, $pageY, $width, $height] = pdf_page_box($pageDict);
        $centerX = $pageX + ($width / 2);
        $centerY = $pageY + ($height / 2);
        if ($imageObject && $imageInfo) {
            // Keep the logo centred on the visible page box and preserve its
            // aspect ratio.  Limiting both dimensions prevents a landscape or
            // non-square watermark from drifting outside the page.
            $maxLogoWidth = $width * 0.82;
            $maxLogoHeight = $height * 0.82;
            $imageWidth = max(1.0, (float) ($imageInfo['width'] ?? 1));
            $imageHeight = max(1.0, (float) ($imageInfo['height'] ?? 1));
            $scale = min($maxLogoWidth / $imageWidth, $maxLogoHeight / $imageHeight);
            $logoWidth = $imageWidth * $scale;
            $logoHeight = $imageHeight * $scale;
            $logoX = $centerX - ($logoWidth / 2);
            $logoY = $centerY - ($logoHeight / 2);
            $titleText = pdf_literal_string('CONFIDENTIAL');
            $detailText = pdf_literal_string((string) preg_replace('/^CONFIDENTIAL\s*-\s*/i', '', $watermarkText));
            $titleFontSize = max(28, min(42, $width / 14));
            $detailLength = max(1, strlen(pdf_ascii_text($watermarkText)));
            $detailFontSize = max(7, min(10, ($width * 1.15) / ($detailLength * 0.52)));
            $titleWidth = $titleFontSize * strlen('CONFIDENTIAL') * 0.52;
            $detailWidth = $detailFontSize * $detailLength * 0.48;
            $formContent = sprintf(
                "q\n/GSWM gs\n%.2F 0 0 %.2F %.2F %.2F cm\n/WMIMG Do\nQ\n" .
                "q\n1 0 0 1 %.2F %.2F cm\n0.8192 0.5736 -0.5736 0.8192 0 0 cm\n/GSWM gs\n" .
                "BT\n/FWM %.2F Tf\n0.56 0.56 0.56 rg\n1 0 0 1 %.2F 10 Tm\n%s Tj\n" .
                "/FWM %.2F Tf\n0.62 0.62 0.62 rg\n1 0 0 1 %.2F -8 Tm\n%s Tj\nET\nQ\n",
                $logoWidth,
                $logoHeight,
                $logoX,
                $logoY,
                $centerX,
                $centerY,
                $titleFontSize,
                -($titleWidth / 2),
                $titleText,
                $detailFontSize,
                -($detailWidth / 2),
                $detailText
            );
            $resources = "/XObject << /WMIMG {$imageObject} 0 R >> /Font << /FWM {$fontObject} 0 R >> /ExtGState << /GSWM {$graphicsStateObject} 0 R >>";
        } else {
            $titleText = pdf_literal_string('CONFIDENTIAL');
            $detailText = pdf_literal_string($watermarkText);
            $titleFontSize = max(34, min(58, $width / 9));
            $detailFontSize = max(10, min(16, $width / 42));
            $titleWidth = $titleFontSize * strlen('CONFIDENTIAL') * 0.52;
            $detailWidth = $detailFontSize * strlen($watermarkText) * 0.34;
            $formContent = sprintf(
                "q\n1 0 0 1 %.2F %.2F cm\n0.707 0.707 -0.707 0.707 0 0 cm\n/GSWM gs\nBT\n/FWM %.2F Tf\n0.72 0.72 0.72 rg\n1 0 0 1 %.2F %.2F Tm\n%s Tj\n/FWM %.2F Tf\n0.76 0.76 0.76 rg\n1 0 0 1 %.2F %.2F Tm\n%s Tj\nET\nQ\n",
                $centerX,
                $centerY,
                $titleFontSize,
                -($titleWidth / 2),
                4,
                $titleText,
                $detailFontSize,
                -($detailWidth / 2),
                -($titleFontSize * 0.55),
                $detailText
            );
            $resources = "/Font << /FWM {$fontObject} 0 R >> /ExtGState << /GSWM {$graphicsStateObject} 0 R >>";
        }
        $formObject = ++$maxObject;
        $formStream = "<< /Type /XObject /Subtype /Form /FormType 1 /BBox [-" . round($width, 2) . ' -' . round($height, 2) . ' ' . round($width * 2, 2) . ' ' . round($height * 2, 2) . "] /Resources << {$resources} >> /Length " . strlen($formContent) . " >>\nstream\n{$formContent}endstream\n";
        $objects[$formObject] = $formStream;

        $overlayContent = "q\n/WMRMUTP Do\nQ\n";
        $overlayObject = ++$maxObject;
        $objects[$overlayObject] = "<< /Length " . strlen($overlayContent) . " >>\nstream\n{$overlayContent}endstream\n";

        $updatedPage = pdf_update_resources($pdf, $pageDict, '/WMRMUTP ' . $formObject . ' 0 R', $objects);
        $objects[$pageObject] = pdf_update_contents($updatedPage, $overlayObject) . "\n";
    }

    ksort($objects, SORT_NUMERIC);
    $append = "\n";
    $offsets = [];
    foreach ($objects as $objectNumber => $body) {
        $offsets[$objectNumber] = strlen($pdf) + strlen($append);
        $append .= $objectNumber . " 0 obj\n" . $body . "endobj\n";
    }
    $xrefOffset = strlen($pdf) + strlen($append);
    $append .= "xref\n";
    foreach ($offsets as $objectNumber => $offset) {
        $append .= $objectNumber . " 1\n" . sprintf('%010d 00000 n ', $offset) . "\n";
    }
    $append .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root {$rootRef} /Prev {$previousXref} >>\n";
    $append .= "startxref\n{$xrefOffset}\n%%EOF\n";

    return file_put_contents($targetPath, $pdf . $append) !== false;
}
