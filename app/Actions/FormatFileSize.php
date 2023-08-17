<?php
namespace App\Actions;

class FormatFileSize
{
    public static function format($size)
    {
        $b = $size;
        $kb = round($size / 1024, 1);
        $mb = round($kb / 1024, 1);
        $gb = round($mb / 1024, 1);

        $result = null;

        if ($kb == 0) {
            $result = $b . " bytes";
        } else if ($mb == 0) {
            $result = $kb . "KB";
        } else if ($gb == 0) {
            $result = $mb . "MB";
        } else {
            $result = $gb . "GB";
        }

        return $result;
    }
}