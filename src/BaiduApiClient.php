<?php
declare(strict_types=1);

namespace Xmanbyby\BaiduXiling;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class BaiduApiException extends \RuntimeException {}

class BaiduApiClient
{
    protected string $apiKey;
    protected string $secretKey;

    protected ?string $accessToken = null;
    protected int $tokenExpire = 0;

    protected Client $http;

    public function __construct(array $options)
    {
        if (empty($options['api_key']) || empty($options['secret_key'])) {
            throw new \InvalidArgumentException('api_key 和 secret_key 必须提供');
        }

        $this->apiKey = $options['api_key'];
        $this->secretKey = $options['secret_key'];

        $this->http = new Client();
    }

    /**
     * 手动设置 AccessToken 及过期时间（时间戳）
     */
    public function setAccessToken(string $token, int $expireTimestamp): void
    {
        $this->accessToken = $token;
        $this->tokenExpire = $expireTimestamp;
    }

    /**
     * 主动请求并获取新的 AccessToken
     * @return array ['access_token' => string, 'expire' => int 时间戳]
     * @throws RuntimeException|GuzzleException
     */
    public function fetchAccessToken(): array
    {
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

        $accessToken = $data['access_token'];
        $expire = time() + (int)$data['expires_in'];

        return [
            'access_token' => $accessToken,
            'expire' => $expire,
        ];
    }

    /**
     * 获取当前缓存的 AccessToken，若无或过期自动刷新
     * 这里不做缓存，只在内存中保留
     * @throws RuntimeException|GuzzleException
     */
    public function getAccessToken(): string
    {
        if ($this->accessToken && $this->tokenExpire > time() + 60) {
            return $this->accessToken;
        }
        $tokenData = $this->fetchAccessToken();
        $this->setAccessToken($tokenData['access_token'], $tokenData['expire']);
        return $this->accessToken;
    }

    /**
     * 发送带鉴权的API请求
     * @throws BaiduApiException
     */
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

    /**
     * 上传文件
     * @throws RuntimeException|BaiduApiException|GuzzleException
     */
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

    /**
     * 创建曦灵数字人音视频合成任务
     */
    public function createSynthesisTask(array $params): array
    {
        $url = 'https://aip.baidubce.com/rpc/2.0/ai_custom/v1/digital_human/synthesis';

        return $this->request('POST', $url, $params, [
            'Content-Type' => 'application/json'
        ]);
    }

    /**
     * 查询曦灵数字人合成任务结果
     */
    public function getSynthesisTaskResult(string $taskId): array
    {
        $url = 'https://aip.baidubce.com/rpc/2.0/ai_custom/v1/digital_human/synthesis/query';

        $params = ['task_id' => $taskId];

        return $this->request('POST', $url, $params, [
            'Content-Type' => 'application/json'
        ]);
    }

    /**
     * 轮询等待合成任务完成
     * @throws RuntimeException|BaiduApiException
     */
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
