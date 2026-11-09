<?php
declare(strict_types=1);

namespace ImageOptimizer\Metadata;

use ImageOptimizer\Exception\Exception;
use ImageOptimizer\Optimizer;

/**
 * Removes metadata from JPEG, PNG and WebP files in pure PHP, without any
 * external tool and without re-encoding the pixels.
 *
 * Removed: EXIF (camera, time, GPS position, thumbnails), XMP, IPTC,
 * comments and text chunks. Kept: what the picture needs to look the same —
 * the colour profile (ICC), JFIF and Adobe segments, and the orientation of
 * a JPEG, written back as a minimal EXIF block holding that one tag.
 *
 * Unlike the command optimizers it never fails silently: a file it cannot
 * read throws, whatever `ignore_errors` says. Other formats are left as they are.
 */
class MetadataStripper implements Optimizer
{
    private const JPEG_SOI = "\xFF\xD8";
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1A\n";

    /** PNG chunks that carry metadata. */
    private const PNG_DROP = ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'];

    /** WebP chunks that carry metadata. */
    private const WEBP_DROP = ['EXIF', 'XMP '];

    public function optimize(string $filepath): void
    {
        $data = @file_get_contents($filepath);
        if ($data === false) {
            throw new Exception(sprintf('Cannot read "%s"', $filepath));
        }
        $stripped = $this->strip($data);
        if ($stripped === $data) {
            return;
        }
        $tmp = $filepath.'.strip-'.bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $stripped) === false || !@rename($tmp, $filepath)) {
            @unlink($tmp);
            throw new Exception(sprintf('Cannot write "%s"', $filepath));
        }
    }

    /**
     * The same image without its metadata; other content is returned as given.
     */
    public function strip(string $data): string
    {
        if (strncmp($data, self::JPEG_SOI, 2) === 0) {
            return $this->jpeg($data);
        }
        if (strncmp($data, self::PNG_SIGNATURE, 8) === 0) {
            return $this->png($data);
        }
        if (strlen($data) >= 12 && substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') {
            return $this->webp($data);
        }

        return $data;
    }

    private function jpeg(string $data): string
    {
        $length = strlen($data);
        $out = self::JPEG_SOI;
        $orientation = 1;
        $kept = [];
        $pos = 2;

        while ($pos < $length) {
            if ($data[$pos] !== "\xFF") {
                throw new Exception('Not a valid JPEG: a marker was expected');
            }
            // Fill bytes before a marker.
            while ($pos < $length && $data[$pos] === "\xFF") {
                $pos++;
            }
            if ($pos >= $length) {
                throw new Exception('Not a valid JPEG: it ends inside a marker');
            }
            $marker = ord($data[$pos]);
            $pos++;

            // Start of scan: the compressed image and the end follow; kept as is.
            if ($marker === 0xDA) {
                $rest = "\xFF\xDA".substr($data, $pos);
                return $out.$this->orientationSegment($orientation).implode('', $kept).$rest;
            }
            if ($marker === 0xD9) {
                return $out.$this->orientationSegment($orientation).implode('', $kept)."\xFF\xD9";
            }
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $kept[] = "\xFF".chr($marker);
                continue;
            }
            if ($pos + 2 > $length) {
                throw new Exception('Not a valid JPEG: a segment has no length');
            }
            $size = unpack('n', substr($data, $pos, 2))[1];
            if ($size < 2 || $pos + $size > $length) {
                throw new Exception('Not a valid JPEG: a segment runs past the end');
            }
            $payload = substr($data, $pos + 2, $size - 2);
            $segment = "\xFF".chr($marker).substr($data, $pos, $size);
            $pos += $size;

            if ($marker === 0xE1) {
                if (strncmp($payload, "Exif\x00\x00", 6) === 0) {
                    $orientation = $this->exifOrientation(substr($payload, 6));
                }
                continue;
            }
            if ($marker === 0xE2) {
                if (strncmp($payload, "ICC_PROFILE\x00", 12) === 0) {
                    $kept[] = $segment;
                }
                continue;
            }
            // APP0 (JFIF) and APP14 (Adobe colour transform) shape the picture; other APPn and comments do not.
            if ($marker === 0xE0 || $marker === 0xEE || (($marker < 0xE0 || $marker > 0xEF) && $marker !== 0xFE)) {
                $kept[] = $segment;
            }
        }

        throw new Exception('Not a valid JPEG: no image data');
    }

    /**
     * Orientation (tag 0x0112) of the first image of a TIFF block, 1 when there is none.
     */
    private function exifOrientation(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return 1;
        }
        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';
        $ifd = unpack($long, substr($tiff, 4, 4))[1];
        if ($ifd + 2 > strlen($tiff)) {
            return 1;
        }
        $count = unpack($short, substr($tiff, $ifd, 2))[1];
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > strlen($tiff)) {
                return 1;
            }
            if (unpack($short, substr($tiff, $entry, 2))[1] === 0x0112) {
                $value = unpack($short, substr($tiff, $entry + 8, 2))[1];
                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    /**
     * An APP1 segment with an EXIF block holding the orientation alone; none for the default.
     */
    private function orientationSegment(int $orientation): string
    {
        if ($orientation === 1) {
            return '';
        }
        $tiff = 'MM'.pack('n', 0x2A).pack('N', 8)
            .pack('n', 1)
            .pack('n', 0x0112).pack('n', 3).pack('N', 1).pack('n', $orientation)."\x00\x00"
            .pack('N', 0);
        $payload = "Exif\x00\x00".$tiff;

        return "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    }

    private function png(string $data): string
    {
        $length = strlen($data);
        $out = self::PNG_SIGNATURE;
        $pos = 8;
        while ($pos < $length) {
            if ($pos + 12 > $length) {
                throw new Exception('Not a valid PNG: a chunk runs past the end');
            }
            $size = unpack('N', substr($data, $pos, 4))[1];
            $type = substr($data, $pos + 4, 4);
            if ($pos + 12 + $size > $length) {
                throw new Exception('Not a valid PNG: a chunk runs past the end');
            }
            if (!in_array($type, self::PNG_DROP, true)) {
                $out .= substr($data, $pos, 12 + $size);
            }
            $pos += 12 + $size;
            if ($type === 'IEND') {
                return $out;
            }
        }

        throw new Exception('Not a valid PNG: no end chunk');
    }

    private function webp(string $data): string
    {
        $length = strlen($data);
        $chunks = '';
        $pos = 12;
        while ($pos < $length) {
            if ($pos + 8 > $length) {
                throw new Exception('Not a valid WebP: a chunk runs past the end');
            }
            $type = substr($data, $pos, 4);
            $size = unpack('V', substr($data, $pos + 4, 4))[1];
            $padded = $size + ($size & 1);
            if ($pos + 8 + $size > $length) {
                throw new Exception('Not a valid WebP: a chunk runs past the end');
            }
            $chunk = substr($data, $pos, 8 + $padded);
            $pos += 8 + $padded;
            if (in_array($type, self::WEBP_DROP, true)) {
                continue;
            }
            if ($type === 'VP8X' && $size >= 1) {
                // Clear the "has EXIF" (0x08) and "has XMP" (0x04) flags.
                $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
            }
            $chunks .= $chunk;
        }

        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
    }
}
