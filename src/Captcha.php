<?php
declare(strict_types=1);

namespace Lengyue\Captcha;

use support\Request;
use think\facade\Cache;


class Captcha
{

    public static function check(string $code, string $key): bool
    {
        $config = config('captcha');
        if (null == Cache::get($key)) {
            return false;
        }
        $hash = Cache::get($key);
        $code = mb_strtolower($code, 'UTF-8');
        if ($code == $hash) {
            Cache::delete($key);
            return true;
        }
        return false;
    }


    public static function base64(array $_config = [])
    {
        $config = config('captcha');
        if (!empty($_config)) {
            $config = array_merge($config, $_config);
        }
        $generator = self::generate($config);
        // 图片宽(px)
        $config['imageW'] || $config['imageW'] = $config['length'] * $config['fontSize'] * 1.5 + $config['length'] * $config['fontSize'] / 2;
        // 图片高(px)
        $config['imageH'] || $config['imageH'] = $config['fontSize'] * 2.5;
        // 建立一幅 $config['imageW'] x $config['imageH'] 的图像
        $im = imagecreate((int)$config['imageW'], (int) $config['imageH']);
        // 设置背景
        imagecolorallocate($im, $config['bg'][0], $config['bg'][1], $config['bg'][2]);

        // 验证码字体随机颜色
        $color = imagecolorallocate($im, mt_rand(1, 150), mt_rand(1, 150), mt_rand(1, 150));

        // 验证码使用随机字体
        $ttfPath = __DIR__ . '/../assets/' . ($config['useZh'] ? 'zhttfs' : 'ttfs') . '/';

        if (empty($config['fontttf'])) {
            $dir  = dir($ttfPath);
            $ttfs = [];
            while (false !== ($file = $dir->read())) {
                if (substr($file, -4) == '.ttf' || substr($file, -4) == '.otf') {
                    $ttfs[] = $file;
                }
            }
            $dir->close();
            $config['fontttf'] = $ttfs[array_rand($ttfs)];
        }

        $fontttf = $ttfPath . $config['fontttf'];

        if ($config['useImgBg']) {
            self::background($config, $im);
        }

        if ($config['useNoise']) {
            // 绘杂点
            self::writeNoise($config, $im);
        }
        if ($config['useCurve']) {
            // 绘干扰线
            self::writeCurve($config, $im, $color);
        }

        // 绘验证码
        $text = $config['useZh'] ? preg_split('/(?<!^)(?!$)/u', $generator['value']) : str_split($generator['value']); // 验证码

        foreach ($text as $index => $char) {
            $x     = $config['fontSize'] * ($index + 1) * mt_rand((int)1.2, (int)1.6) * ($config['math'] ? 1 : 1.5);
            $y     = $config['fontSize'] + mt_rand(10, 20);
            $angle = $config['math'] ? 0 : mt_rand(-40, 40);
            imagettftext($im, $config['fontSize'], $angle, (int) $x, (int) $y, $color, $fontttf, $char);
        }

        ob_start();
        imagepng($im);
        $content = ob_get_clean();
        imagedestroy($im);

        return [
            'key' => $generator['key'],
            'base64' => 'data:image/png;base64,' . base64_encode($content),
        ];
    }


    protected static function generate(array $config): array
    {
        $bag = '';
        $ckey = uniqid();
        
        if ($config['math']) {
            $config['useZh']  = false;
            $config['length'] = 5;
            $x   = random_int(10, 30);
            $y   = random_int(1, 9);
            $bag = "{$x} + {$y} = ";
            $key = $x + $y;
            $key .= '';
        } else {
            if ($config['useZh']) {
                $characters = preg_split('/(?<!^)(?!$)/u', $config['zhSet']);
            } else {
                $characters = str_split($config['codeSet']);
            }

            for ($i = 0; $i < $config['length']; $i++) {
                $bag .= $characters[rand(0, count($characters) - 1)];
            }

            $key = mb_strtolower($bag, 'UTF-8');
        }
        Cache::set($ckey, $key,60);
        return ['value' => $bag, 'key' => $ckey];
    }


    protected static function writeCurve(array $config, $im, $color): void
    {
        $py = 0;
        // 曲线前部分
        $A = mt_rand(1, (int) ($config['imageH'] / 2)); // 振幅
        $b = mt_rand(-(int) ($config['imageH'] / 4), (int) ($config['imageH'] / 4)); // Y轴方向偏移量
        $f = mt_rand(-(int) ($config['imageH'] / 4), (int) ($config['imageH'] / 4)); // X轴方向偏移量
        $T = mt_rand((int) $config['imageH'], (int) ($config['imageW'] * 2)); // 周期
        $w = (2 * M_PI) / $T;

        $px1 = 0; // 曲线横坐标起始位置
        $px2 = mt_rand((int) ($config['imageW'] / 2), (int)$config['imageW']); // 曲线横坐标结束位置

        for ($px = $px1; $px <= $px2; $px = $px + 1) {
            if (0 != $w) {
                $py = $A * sin($w * $px + $f) + $b + $config['imageH'] / 2; // y = Asin(ωx+φ) + b
                $i  = (int) ($config['fontSize'] / 5);
                while ($i > 0) {
                    imagesetpixel($im, (int) $px + $i, (int) $py + $i, (int)$color); // 这里(while)循环画像素点比imagettftext和imagestring用字体大小一次画出（不用这while循环）性能要好很多
                    $i--;
                }
            }
        }

        // 曲线后部分
        $A   = mt_rand(1, (int) ($config['imageH'] / 2)); // 振幅
        $f   = mt_rand(-(int) ($config['imageH'] / 4), (int) ($config['imageH'] / 4)); // X轴方向偏移量
        $T   = mt_rand((int) $config['imageH'], (int) ($config['imageW'] * 2)); // 周期
        $w   = (2 * M_PI) / $T;
        $b   = $py - $A * sin($w * $px + $f) - $config['imageH'] / 2;
        $px1 = $px2;
        $px2 = $config['imageW'];

        for ($px = $px1; $px <= $px2; $px = $px + 1) {
            if (0 != $w) {
                $py = $A * sin($w * $px + $f) + $b + $config['imageH'] / 2; // y = Asin(ωx+φ) + b
                $i  = (int) ($config['fontSize'] / 5);
                while ($i > 0) {
                    imagesetpixel($im, (int) $px + $i, (int) $py + $i, (int)$color);
                    $i--;
                }
            }
        }
    }


    protected static function writeNoise(array $config, $im): void
    {
        $codeSet = 'tinywan20222345678abcdefhijkmnpqrstuvwxyz';
        for ($i = 0; $i < 10; $i++) {
            //杂点颜色
            $noiseColor = imagecolorallocate($im, mt_rand(150, 225), mt_rand(150, 225), mt_rand(150, 225));
            for ($j = 0; $j < 5; $j++) {
                // 绘杂点
                imagestring($im, 5, mt_rand(-10, (int) $config['imageW']), mt_rand(-10, (int) $config['imageH']), $codeSet[mt_rand(0, 29)], (int) $noiseColor);
            }
        }
    }


    protected static function background(array $config, $im): void
    {
        $path = __DIR__ . '/../assets/bgs/';
        $dir  = dir($path);

        $bgs = [];
        while (false !== ($file = $dir->read())) {
            if ('.' != $file[0] && substr($file, -4) == '.jpg') {
                $bgs[] = $path . $file;
            }
        }
        $dir->close();

        $gb = $bgs[array_rand($bgs)];

        [$width, $height] = @getimagesize($gb);
        $bgImage = @imagecreatefromjpeg($gb);
        @imagecopyresampled($im, $bgImage, 0, 0, 0, 0, (int) $config['imageW'], (int) $config['imageH'], $width, $height);
        @imagedestroy($bgImage);
    }
}
