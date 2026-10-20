<?php

namespace App\Support;

use App\Models\Dispatch;
use App\Models\User;

/**
 * 1200x630 PNGs for link previews in iMessage, Slack and social apps.
 */
class ShareImage
{
    public static function forDispatch(Dispatch $dispatch): string
    {
        $color = $dispatch->lineColor();
        $place = $dispatch->stop ? $dispatch->stop.' · '.$dispatch->stopName() : $dispatch->lineName();

        return self::draw($color, str($dispatch->body)->limit(180)->toString(), $dispatch->user->name.'  @'.$dispatch->user->username, $place);
    }

    public static function forUser(User $user): string
    {
        return self::draw($user->color(), $user->name, '@'.$user->username.'  ·  '.$user->badge(), 'Stoop · DC, block by block', 60);
    }

    protected static function draw(string $accent, string $headline, string $byline, string $place, int $size = 40): string
    {
        $display = resource_path('fonts/Newsreader.ttf');
        $sans = resource_path('fonts/PublicSans.ttf');

        $img = imagecreatetruecolor(1200, 630);
        imagefill($img, 0, 0, imagecolorallocate($img, 242, 243, 241));
        [$r, $g, $b] = sscanf($accent, '#%02x%02x%02x');
        imagefilledrectangle($img, 0, 0, 1200, 16, imagecolorallocate($img, $r, $g, $b));
        $ink = imagecolorallocate($img, 22, 24, 26);
        $mute = imagecolorallocate($img, 93, 99, 102);

        imagettftext($img, 30, 0, 80, 110, $ink, $display, 'Stoop');
        imagettftext($img, 20, 0, 80, 560, $mute, $sans, $byline);
        $box = imagettfbbox(20, 0, $sans, $place);
        imagettftext($img, 20, 0, 1120 - ($box[2] - $box[0]), 560, $mute, $sans, $place);

        // Wrap the headline to the width of the card, at most five lines.
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/', $headline) as $word) {
            $try = trim("$line $word");
            $box = imagettfbbox($size, 0, $display, $try);
            if ($box[2] - $box[0] > 1040 && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        $lines[] = $line;
        foreach (array_slice($lines, 0, 5) as $i => $text) {
            imagettftext($img, $size, 0, 80, 200 + $i * (int) ($size * 1.5), $ink, $display, $text);
        }

        ob_start();
        imagepng($img);

        return ob_get_clean();
    }
}
