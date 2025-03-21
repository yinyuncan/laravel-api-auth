<?php
/**
 * User: yuncan
 * Date: 2021/4/16
 * Time: 下午3:05
 */


namespace Yuncan\LaravelApiAuth\Signatures;

interface SignatureInterface
{
    public static function sign(string $string, string $secret): string;

    public static function check(string $string, string $secret, string $signature): bool;

}
