<?php

namespace Yuncan\LaravelApiAuth;

use Closure;
use Illuminate\Http\Request;
use Yuncan\LaravelApiAuth\Exceptions\AccessKeyException;
use Yuncan\LaravelApiAuth\Exceptions\InvalidTokenException;
use Yuncan\LaravelApiAuth\Exceptions\SignatureMethodException;
use Yuncan\LaravelApiAuth\Exceptions\TimeExpireException;
use Yuncan\LaravelApiAuth\Signatures\SignatureInterface;

class Middleware
{
    const STATUS_ON = 'on';
    const STATUS_OFF = 'off';
    private $config;

    public function __construct()
    {
        $this->config = config('api_auth');
    }

    /**
     * @param Request $request
     * @param \Closure $next
     *
     * @return mixed
     * @throws \Yuncan\LaravelApiAuth\Exceptions\AccessKeyException
     * @throws \Yuncan\LaravelApiAuth\Exceptions\InvalidTokenException
     * @throws \Yuncan\LaravelApiAuth\Exceptions\SignatureMethodException
     */
    public function handle($request, Closure $next)
    {
        if ($this->config['status'] === static::STATUS_ON && !$this->is_skip($request)) {

            if (!$request->hasHeader(isset($this->config['header'])? $this->config['header'] : 'Authorization')) {
                return response()->json(['code' => '70001', 'msg' => '未授权', 'data' => 'fail'], 203);
            }
            // 得到 api token
            $token = $request->header($this->config['header']);
            // 得到 header 、 payload 、 signature 三段字符串
//            list($header_string, $payload_string, $signature) = explode(".", $token);
            if (count(explode(".", $token)) != 3 || empty($token)) {
                throw new AccessKeyException('access key invalid !');
            }
            $res = explode(".", $token);
            $header_string = $res[0];
            $payload_string = $res[1];
            $signature = $res[2];
//            print_r(explode(".", $token));

//            list($header, $payload, $alg) = $this->parseParams($header_string, $payload_string);
            $res2 = $this->parseParams($header_string, $payload_string);
            $header = $res2['header'];
            $payload = $res2['payload'];
            $alg = $res2['alg'];
//            print_r($this->parseParams($header_string, $payload_string));

            $role = $this->config['roles'][$payload['ak']];

            // 检查签名是否正确
            $this->signatureCheck($alg, "$header_string.$payload_string", $role['secret_key'], $signature);

            $request = $this->bindParamsToRequest($request, $role['name'], $payload);
        }

        return $next($request);
    }

    /**
     * 各种参数校验和解析
     *
     * @param string $header_string
     * @param string $payload_string
     *
     * @return array
     * @throws AccessKeyException
     * @throws InvalidTokenException
     * @throws SignatureMethodException
     */
    public function parseParams(string $header_string, string $payload_string): array
    {
        // 检查参数 --begin
        $header = @json_decode(base64_decode($header_string), true);
        $payload = @json_decode(base64_decode($payload_string), true);

        if (!is_array($header) ||
            !isset($header['alg']) ||
            !is_array($payload) ||
            !isset($payload['timestamp']) ||
            !isset($payload['echostr']) ||
            !isset($payload['ak'])
        ) {
            throw new InvalidTokenException('invalid token !');
        }

        if (strtotime(date('Y-m-d H:i:s')) - $payload['timestamp'] > $this->config['timeout']) {
            throw new TimeExpireException('签名超时!');
        }

        if (!isset($this->config['roles'][$payload['ak']])) {
            throw new AccessKeyException('access key invalid !');
        }

        if (!isset($this->config['signature_methods'][$header['alg']])) {
            throw new SignatureMethodException($header['alg'] . ' signatures are not supported !');
        }

        $alg = $this->config['signature_methods'][$header['alg']];

        if (!class_exists($alg)) {
            throw new SignatureMethodException($header['alg'] . ' signatures method configuration error !');
        }

        $alg = new $alg;

        if (!$alg instanceof SignatureInterface) {
            throw new SignatureMethodException($header['alg'] . ' signatures method configuration error !');
        }

        // 检查参数 --end

        return compact('header', 'payload', 'alg');
    }

    /**
     * 校验签名是否正确
     *
     * @param SignatureInterface $alg
     * @param string $signature_string
     * @param string $secret
     * @param                    $signature
     *
     * @throws InvalidTokenException
     */
    public function signatureCheck(SignatureInterface $alg, string $signature_string, string $secret, $signature): void
    {
        if (!$alg::check($signature_string, $secret, $signature)) {
            throw new InvalidTokenException('invalid token !');
        }
    }

    /**
     * @param Request $request
     * @param string $role_name
     * @param array $payload
     *
     * @return Request
     */
    public function bindParamsToRequest($request, string $role_name, array $payload)
    {
        // 添加 role_name 到 $request 中
        if ($request->has('client_role')) {
            $request->offsetSet('_client_role', $request->get('client_role'));
        }
        $request->offsetSet('client_role', $role_name);

        // 添加 api_payload 到 $request 中
        if ($request->has('api_payload')) {
            $request->offsetSet('_api_payload', $request->get('api_payload'));
        }
        $request->offsetSet('api_payload', $payload);

        return $request;
    }

    public function is_skip(Request $request): bool
    {
        $handler = [static::class, 'default_skip_handler'];

        if (is_callable($this->config['skip']['is'])) {
            $handler = $this->config['skip']['is'];
        }

        return call_user_func_array($handler, [$request, $this->config['skip']['urls']]);
    }

    /**
     * @param Request $request
     * @param array $urls
     *
     * @return bool
     */
    public static function default_skip_handler(Request $request, array $urls = []): bool
    {
        if (in_array($request->url(), $urls)) {
            return true;
        }

        return false;
    }

}
