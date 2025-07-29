<?php
declare(strict_types=1);

namespace Xmanbyby\BaiduXiling;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * Class BaiduApiClient
 * 百度开放平台通用API客户端
 */
class BaiduApiClient
{
    protected string $apiKey;
    protected string $secretKey;

    protected ?string $accessToken = null;
    protected int $tokenExpire = 0;

    protected Client $http;
    protected CacheInterface $cache;

    protected string $cacheKey;
    protected int $cacheTtl = 3600;

    /**
     * @param array $options 配置项，必须包含 api_key 和 secret_key
     * @param CacheInterface $cache PSR-16 缓存实例
     */
    public function __construct(array $options, CacheInterface $cache)
    {
        if (empty($options['api_key']) || empty($options['secret_key'])) {
            throw new \InvalidArgumentException('api_key 和 secret_key 必须提供');
        }

        $this->apiKey = $options['api_key'];
        $this->secretKey = $options['secret_key'];

        $this->cache = $cache;

        $cacheKeyPrefix = $options['cache_key_prefix'] ?? 'baidu_api_access_token_';
        $this->cacheKey = $cacheKeyPrefix . md5($this->apiKey);

        $this->cacheTtl = $options['cache_ttl'] ?? 3600;

        $this->http = new Client();

        $this->loadTokenFromCache();
    }

    protected function loadTokenFromCache(): void
    {
        $tokenData = $this->cache->get($this->cacheKey);
        if (is_array($tokenData) && isset($tokenData['token'], $tokenData['expire']) && $tokenData['expire'] > time()) {
            $this->accessToken = $tokenData['token'];
            $this->tokenExpire = $tokenData['expire'];
        }
    }

    /**
     * @return string
     * @throws RuntimeException|GuzzleException
     */
    public function getAccessToken(): string
    {
        if ($this->accessToken && $this->tokenExpire > time() + 60) {
            return $this->accessToken;
        }

        $url = 'https://aip.baidubce.com/oauth/2.0/token';

        $response = $this->http->post($url, [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->apiKey,
                'client_secret' => $this->secretKey,
            ],
        ]);

        $data = json_decode((string)$response->getBody(), true);

        if (empty($data['access_token'])) {
            throw new RuntimeException('获取百度 AccessToken 失败: ' . json_encode($data));
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpire = time() + (int)$data['expires_in'];
        $ttl = max(($data['expires_in'] ?? $this->cacheTtl) - 60, 1);

        $this->cache->set($this->cacheKey, [
            'token' => $this->accessToken,
            'expire' => $this->tokenExpire,
        ], $ttl);

        return $this->accessToken;
    }

    public function request(string $method, string $url, array $params = [], array $headers = []): array
    {
        $headers = array_merge([
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], $headers);

        $options = ['headers' => $headers];

        if (strtolower($headers['Content-Type']) === 'application/json') {
            $options['json'] = $params;
        } else {
            $options['form_params'] = $params;
        }

        try {
            $response = $this->http->request($method, $url, $options);
            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error']) && $data['error']) {
                throw new BaiduApiException($data['error_description'] ?? 'Unknown error');
            }

            return $data;
        } catch (\Throwable $e) {
            throw new BaiduApiException('百度接口请求失败: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function uploadFile(string $filePath, array $params = []): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("文件不存在: $filePath");
        }

        $accessToken = $this->getAccessToken();
        $url = "https://aip.baidubce.com/rest/2.0/ai_dh/file/upload?access_token={$accessToken}";

        $multipart = [
            [
                'name'     => 'file',
                'contents' => fopen($filePath, 'r'),
                'filename' => basename($filePath),
            ]
        ];

        foreach ($params as $key => $value) {
            $multipart[] = [
                'name'     => $key,
                'contents' => $value,
            ];
        }

        $response = $this->http->request('POST', $url, [
            'multipart' => $multipart,
        ]);

        $body = json_decode((string)$response->getBody(), true);

        if (!is_array($body)) {
            throw new RuntimeException('接口响应格式错误');
        }

        return $body;
    }

    public function createSynthesisTask(array $params): array
    {
        $url = 'https://aip.baidubce.com/rpc/2.0/ai_custom/v1/digital_human/synthesis';

        return $this->request('POST', $url, $params, [
            'Content-Type' => 'application/json'
        ]);
    }

    public function getSynthesisTaskResult(string $taskId): array
    {
        $url = 'https://aip.baidubce.com/rpc/2.0/ai_custom/v1/digital_human/synthesis/query';

        $params = ['task_id' => $taskId];

        return $this->request('POST', $url, $params, [
            'Content-Type' => 'application/json'
        ]);
    }

    public function waitForSynthesisResult(string $taskId, int $timeout = 30, int $interval = 3): array
    {
        $startTime = time();
        while (true) {
            $result = $this->getSynthesisTaskResult($taskId);
            $status = $result['task_status'] ?? '';

            if ($status === 'Success') {
                return $result;
            }

            if ($status === 'Failed') {
                throw new RuntimeException("合成任务失败，task_id={$taskId}，错误信息：" . json_encode($result, JSON_UNESCAPED_UNICODE));
            }

            if (time() - $startTime >= $timeout) {
                throw new RuntimeException("合成任务超时未完成，task_id={$taskId}");
            }
            sleep($interval);
        }
    }
}
