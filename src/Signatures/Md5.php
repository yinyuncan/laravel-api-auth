<?php
/**
 * User: Jasonccs
 * Date: 2021/4/16
 * Time: 下午3:05
 */


namespace Jasonccs\LaravelApiAuth\Signatures;


class Md5 implements SignatureInterface
{
    public static function sign(string $string, string $secret): string
    {
        return md5($string . $secret);
    }

    public static function check(string $string, string $secret, string $signature): bool
    {
        return static::sign($string, $secret) === $signature;
    }

}
