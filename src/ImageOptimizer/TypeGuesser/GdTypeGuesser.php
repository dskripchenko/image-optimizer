<?php
declare(strict_types=1);

namespace ImageOptimizer\TypeGuesser;


class GdTypeGuesser implements TypeGuesser
{
    public function __construct()
    {
        if(!function_exists('gd_info')) {
            throw new \RuntimeException(sprintf('%s class require gd extension to be enabled', __CLASS__));
        }
    }

    public function guess(string $filepath): string
    {
        // `getimagesize()` returns false for anything that is not an image —
        // an SVG, a PHP file, a broken download. Up to PHP 8.4 destructuring a
        // false merely warned and left $type null, so the switch below fell
        // through to "unknown" and the whole thing looked correct. PHP 8.5
        // turned that into a TypeError, and a guesser whose job is to answer
        // "unknown" started taking the caller down with it.
        $size = getimagesize($filepath);

        if (! is_array($size)) {
            return self::TYPE_UNKNOWN;
        }

        $type = $size[2] ?? null;

        switch($type) {
            case \IMAGETYPE_PNG:
                return self::TYPE_PNG;
            case \IMAGETYPE_GIF:
                return self::TYPE_GIF;
            case \IMAGETYPE_JPEG:
            case \IMAGETYPE_JPEG2000:
                return self::TYPE_JPEG;
            default:
                return self::TYPE_UNKNOWN;
        }
    }
}