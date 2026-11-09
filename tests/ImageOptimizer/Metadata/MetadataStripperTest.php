<?php
declare(strict_types=1);

namespace ImageOptimizer\Metadata;

use ImageOptimizer\Exception\Exception;
use ImageOptimizer\OptimizerFactory;
use PHPUnit\Framework\TestCase;

class MetadataStripperTest extends TestCase
{
    /** A TIFF block with the orientation, a GPS latitude and a camera name. */
    private function exif(int $orientation): string
    {
        $make = "SecretCam\x00";
        $tiff = 'II'.pack('v', 0x2A).pack('V', 8)
            .pack('v', 3)
            .pack('v', 0x010F).pack('v', 2).pack('V', strlen($make)).pack('V', 50)
            .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation)."\x00\x00"
            .pack('v', 0x8825).pack('v', 4).pack('V', 1).pack('V', 60)
            .pack('V', 0);
        $tiff = str_pad($tiff, 50, "\x00").$make;
        $tiff = str_pad($tiff, 60, "\x00").pack('v', 1).pack('v', 0x0002).pack('v', 5).pack('V', 3).pack('V', 0).'GPS-55.7558N';

        return "Exif\x00\x00".$tiff;
    }

    private function segment(int $marker, string $payload): string
    {
        return "\xFF".chr($marker).pack('n', strlen($payload) + 2).$payload;
    }

    private function picture(string $format): string
    {
        $image = imagecreatetruecolor(16, 8);
        imagefilledrectangle($image, 0, 0, 7, 7, imagecolorallocate($image, 200, 30, 30));
        ob_start();
        $format === 'jpeg' ? imagejpeg($image, null, 90) : ($format === 'png' ? imagepng($image) : imagewebp($image, null, 90));
        return (string) ob_get_clean();
    }

    private function jpegWithMetadata(int $orientation = 6): string
    {
        $jpeg = $this->picture('jpeg');
        $icc = $this->segment(0xE2, "ICC_PROFILE\x00\x01\x01profile-bytes");

        return "\xFF\xD8"
            .$this->segment(0xE1, $this->exif($orientation))
            .$this->segment(0xE1, "http://ns.adobe.com/xap/1.0/\x00<x:xmpmeta>Author: Jane</x:xmpmeta>")
            .$this->segment(0xED, "Photoshop 3.0\x00IPTC caption")
            .$this->segment(0xFE, 'a comment about the place')
            .$icc
            .substr($jpeg, 2);
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    /** @test */
    public function itRemovesJpegMetadataAndKeepsOrientationProfileAndPixels()
    {
        $before = $this->jpegWithMetadata(6);
        $after = (new MetadataStripper())->strip($before);

        foreach (['SecretCam', 'GPS-55', 'xmpmeta', 'IPTC caption', 'a comment'] as $leak) {
            $this->assertStringNotContainsString($leak, $after);
        }
        $this->assertStringContainsString('profile-bytes', $after);
        $this->assertSame(6, $this->orientationOf($after));
        $this->assertSame($this->pixels($before), $this->pixels($after));
    }

    /** @test */
    public function itWritesNoExifWhenTheOrientationIsTheDefault()
    {
        $after = (new MetadataStripper())->strip($this->jpegWithMetadata(1));

        $this->assertStringNotContainsString('Exif', $after);
    }

    /** @test */
    public function itRemovesPngTextAndExifChunks()
    {
        $png = $this->picture('png');
        $withMeta = substr($png, 0, 33)
            .$this->pngChunk('tEXt', "Author\x00Jane")
            .$this->pngChunk('eXIf', substr($this->exif(1), 6))
            .$this->pngChunk('iTXt', "XML:com.adobe.xmp\x00\x00\x00\x00\x00<xmp>secret</xmp>")
            .substr($png, 33);
        $after = (new MetadataStripper())->strip($withMeta);

        $this->assertSame($png, $after);
    }

    /** @test */
    public function itRemovesWebpExifAndXmpAndClearsTheirFlags()
    {
        $simple = $this->picture('webp');
        $image = substr($simple, 12);
        $vp8x = 'VP8X'.pack('V', 10).chr(0x0C)."\x00\x00\x00".substr(pack('V', 15), 0, 3).substr(pack('V', 7), 0, 3);
        $exif = substr($this->exif(1), 6);
        $exifChunk = 'EXIF'.pack('V', strlen($exif)).$exif.(strlen($exif) % 2 ? "\x00" : '');
        $xmp = '<xmp>secret</xmp>';
        $xmpChunk = 'XMP '.pack('V', strlen($xmp)).$xmp.(strlen($xmp) % 2 ? "\x00" : '');
        $chunks = $vp8x.$image.$exifChunk.$xmpChunk;
        $webp = 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;

        $after = (new MetadataStripper())->strip($webp);

        $this->assertStringNotContainsString('SecretCam', $after);
        $this->assertStringNotContainsString('secret', $after);
        $this->assertSame(0, ord($after[20]) & 0x0C);
        $this->assertSame(strlen($after) - 8, unpack('V', substr($after, 4, 4))[1]);
        if (function_exists('imagecreatefromwebp')) {
            $this->assertNotFalse(@imagecreatefromstring($after));
        }
    }

    /** @test */
    public function itLeavesOtherFilesAlone()
    {
        $this->assertSame('GIF89a...', (new MetadataStripper())->strip('GIF89a...'));
        $this->assertSame('plain text', (new MetadataStripper())->strip('plain text'));
    }

    /** @test */
    public function itRefusesABrokenImageInsteadOfLeavingMetadata()
    {
        $this->expectException(Exception::class);
        (new MetadataStripper())->strip("\xFF\xD8\xFF\xE1\x00\xFFExif");
    }

    /** @test */
    public function theFactoryGivesItAndItRewritesTheFileEvenWhenErrorsAreIgnored()
    {
        $path = sys_get_temp_dir().'/strip-'.bin2hex(random_bytes(4)).'.jpg';
        file_put_contents($path, $this->jpegWithMetadata(3));

        (new OptimizerFactory(['ignore_errors' => true]))->get('strip_metadata')->optimize($path);

        $this->assertStringNotContainsString('GPS-55', (string) file_get_contents($path));
        $this->assertSame(3, $this->orientationOf((string) file_get_contents($path)));
        unlink($path);

        $this->expectException(Exception::class);
        (new OptimizerFactory(['ignore_errors' => true]))->get('strip_metadata')->optimize($path);
    }

    private function orientationOf(string $jpeg): int
    {
        $path = sys_get_temp_dir().'/orient-'.bin2hex(random_bytes(4)).'.jpg';
        file_put_contents($path, $jpeg);
        $exif = function_exists('exif_read_data') ? @exif_read_data($path) : false;
        unlink($path);
        if ($exif === false) {
            $this->markTestSkipped('ext-exif is needed to read the orientation back');
        }

        return (int) ($exif['Orientation'] ?? 1);
    }

    private function pixels(string $image): string
    {
        $gd = imagecreatefromstring($image);
        $this->assertNotFalse($gd);
        $out = '';
        for ($y = 0; $y < imagesy($gd); $y++) {
            for ($x = 0; $x < imagesx($gd); $x++) {
                $out .= pack('V', imagecolorat($gd, $x, $y));
            }
        }

        return $out;
    }
}
